<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\UnsubscribeToken;

class GetUnsubscribeToken
{
    use ResolvesModel;

    /**
     * Returns the stored token so links already sent stay valid. A fresh one
     * is minted, invalidating older links, only when none can be recovered,
     * e.g. after an APP_KEY rotation without the old key.
     *
     * The URL generator is resolved here rather than injected, so the steps
     * that build no link never depend on its settings.
     */
    public function __invoke(WaitlistEntry $entry): UnsubscribeToken
    {
        $token = $entry->plainUnsubscribeToken() ?? $this->mint($entry);

        return new UnsubscribeToken(token: $token, url: app(ConfirmationUrlGenerator::class)->unsubscribeUrl($entry, $token));
    }

    /**
     * Conditional on the hash it replaces: of two requests minting at once,
     * one writes, and the other hands out the winner's token, so no link
     * already on its way dies.
     */
    protected function mint(WaitlistEntry $entry, bool $retried = false): string
    {
        $token = Str::random(64);
        $replaced = $entry->getRawOriginal('unsubscribe_token_hash');

        // Drop an unreadable stored payload before filling: the encrypted
        // cast would otherwise try to decrypt it.
        $entry->setRawAttributes(
            array_merge($entry->getAttributes(), ['unsubscribe_token' => null]),
            true,
        );

        // Filled first, so the cast encrypts the token for the update.
        $entry->forceFill([
            'unsubscribe_token_hash' => static::modelClass()::hashToken($token),
            'unsubscribe_token' => $token,
        ]);

        // The query builder adds the updated_at column the model names.
        $written = static::modelClass()::query()
            ->whereKey($entry->getKey())
            ->where('unsubscribe_token_hash', $replaced)
            ->update([
                'unsubscribe_token_hash' => $entry->getAttributes()['unsubscribe_token_hash'],
                'unsubscribe_token' => $entry->getAttributes()['unsubscribe_token'],
            ]);

        if ($written === 1) {
            $entry->syncOriginal();

            return $token;
        }

        // Another request minted first, or the entry is gone. Locked inside a
        // transaction, to read past a snapshot the application's may hold.
        $query = static::modelClass()::query()->whereKey($entry->getKey());
        $stored = (static::waitlistConnection()->transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();
        $winner = $stored?->plainUnsubscribeToken();

        if ($stored === null || ($winner === null && $retried)) {
            throw (new ModelNotFoundException)->setModel($entry::class);
        }

        $entry->setRawAttributes($stored->getAttributes(), true);

        return $winner ?? $this->mint($entry, retried: true);
    }
}
