<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ManageLink;
use Taldres\Waitlist\Support\ResolvesModel;

class IssueManageLink
{
    use ResolvesModel;

    public function __construct(
        protected ConfirmationUrlGenerator $urls,
    ) {}

    /**
     * Replaces any existing manage token. Only the hash is stored: the plain
     * token exists only in the link you send.
     */
    public function __invoke(WaitlistEntry $entry): ManageLink
    {
        $token = Str::random(64);
        $expiresAt = WaitlistConfig::manageTokenExpiresAt(Carbon::now());

        static::modelClass()::query()->whereKey($entry->getKey())->update([
            'manage_token_hash' => static::modelClass()::hashToken($token),
            'manage_token_expires_at' => $expiresAt,
        ]);

        $entry->forceFill([
            'manage_token_hash' => static::modelClass()::hashToken($token),
            'manage_token_expires_at' => $expiresAt,
        ])->syncOriginalAttributes(['manage_token_hash', 'manage_token_expires_at']);

        return new ManageLink(token: $token, url: $this->urls->manageUrl($entry, $token), expiresAt: $expiresAt);
    }

    /**
     * Minutes a manage link stays valid, at least one: a link that never
     * expires would stand in for a login forever.
     */
    public static function ttl(): int
    {
        return WaitlistConfig::manageTokenTtl();
    }
}
