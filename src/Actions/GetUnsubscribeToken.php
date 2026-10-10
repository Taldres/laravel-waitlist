<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\Str;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\UnsubscribeToken;

class GetUnsubscribeToken
{
    use ResolvesModel;

    public function __construct(
        protected ConfirmationUrlGenerator $urls,
    ) {}

    /**
     * Returns the stored token so links already sent stay valid. A fresh one
     * is minted, invalidating older links, only when none can be recovered,
     * e.g. after an APP_KEY rotation without the old key.
     */
    public function __invoke(WaitlistEntry $entry): UnsubscribeToken
    {
        $token = $entry->plainUnsubscribeToken() ?? $this->mint($entry);

        return new UnsubscribeToken(token: $token, url: $this->urls->unsubscribeUrl($entry, $token));
    }

    protected function mint(WaitlistEntry $entry): string
    {
        $token = Str::random(64);

        // Drop an unreadable stored payload before saving — Eloquent's
        // dirty check would otherwise try to decrypt it and throw.
        $entry->setRawAttributes(
            array_merge($entry->getAttributes(), ['unsubscribe_token' => null]),
            true,
        );

        $entry->forceFill([
            'unsubscribe_token_hash' => static::modelClass()::hashToken($token),
            'unsubscribe_token' => $token,
        ])->save();

        return $token;
    }
}
