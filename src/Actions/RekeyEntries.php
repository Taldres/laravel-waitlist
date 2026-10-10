<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\BlindIndex;
use Taldres\Waitlist\Support\RekeyResult;
use Taldres\Waitlist\Support\ResolvesModel;

class RekeyEntries
{
    use ResolvesModel;

    private const array ACTIVITY_COLUMNS = ['ip', 'user_agent', 'reference'];

    /**
     * Rewrites every stored value and the lookup hash under the current key,
     * so the keys in APP_PREVIOUS_KEYS (or a custom encrypter's other keys)
     * can be retired. Rows no key can decrypt stay as they are.
     */
    public function __invoke(): RekeyResult
    {
        $entries = $unreadable = $duplicates = $activity = 0;

        static::modelClass()::query()->lazyById(500)->each(function (WaitlistEntry $entry) use (&$entries, &$unreadable, &$duplicates): void {
            match ($this->rekeyEntry($entry)) {
                'rewritten' => $entries++,
                'unreadable' => $unreadable++,
                'duplicate' => $duplicates++,
                'gone' => null,
            };
        });

        static::activityModelClass()::query()
            ->where(fn (Builder $query): Builder => $query->whereNotNull('ip')->orWhereNotNull('user_agent')->orWhereNotNull('reference'))
            ->lazyById(500)
            ->each(function (WaitlistActivity $row) use (&$activity): void {
                $activity += (int) $this->rekeyActivity($row);
            });

        return new RekeyResult(entries: $entries, activity: $activity, unreadable: $unreadable, duplicates: $duplicates);
    }

    /**
     * Through the query builder: saving the model would compare against
     * payloads only the previous key can read. The row is locked and re-read,
     * so a signup landing meanwhile is not overwritten.
     *
     * @return 'rewritten'|'unreadable'|'duplicate'|'gone'
     */
    protected function rekeyEntry(WaitlistEntry $entry): string
    {
        try {
            return static::waitlistConnection()->transaction(function () use ($entry): string {
                $raw = static::modelClass()::query()->whereKey($entry->getKey())->lockForUpdate()->first()?->getAttributes();

                if ($raw === null) {
                    return 'gone';
                }

                if (! is_string($raw['email'] ?? null)) {
                    return 'unreadable';
                }

                $encrypter = static::modelClass()::currentEncrypter();

                try {
                    $email = self::decrypt($encrypter, $raw['email']);
                    $metadata = is_string($raw['metadata'] ?? null) ? self::decrypt($encrypter, $raw['metadata']) : null;
                    $token = is_string($raw['unsubscribe_token'] ?? null) ? self::decrypt($encrypter, $raw['unsubscribe_token']) : null;
                } catch (DecryptException) {
                    return 'unreadable';
                }

                // Through the base query: a new key is no change to the
                // person's data, so updated_at stays as it was.
                static::modelClass()::query()->whereKey($entry->getKey())->toBase()->update([
                    'email' => $encrypter->encrypt($email, false),
                    'email_hash' => BlindIndex::hash($email),
                    'metadata' => $metadata === null ? null : $encrypter->encrypt($metadata, false),
                    'unsubscribe_token' => $token === null ? null : $encrypter->encrypt($token, false),
                ]);

                return 'rewritten';
            });
        } catch (UniqueConstraintViolationException) {
            // The same address on the same list, written under the new key by
            // a race at signup. Both stay; erase one with waitlist:forget.
            // Caught outside the transaction: Postgres aborts it on the
            // violation.
            return 'duplicate';
        }
    }

    /**
     * Log rows are immutable as models, so this goes through the query builder
     * like erasure, and only while the row still holds what was read: an
     * erasure clearing it meanwhile must win.
     */
    protected function rekeyActivity(WaitlistActivity $row): bool
    {
        $raw = array_intersect_key($row->getAttributes(), array_flip(self::ACTIVITY_COLUMNS));
        $rewritten = [];
        $encrypter = static::activityModelClass()::currentEncrypter();

        try {
            foreach ($raw as $column => $value) {
                $rewritten[$column] = is_string($value) ? $encrypter->encrypt(self::decrypt($encrypter, $value), false) : null;
            }
        } catch (DecryptException) {
            return false;
        }

        $query = static::activityModelClass()::query()->whereKey($row->getKey());

        foreach ($raw as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->update($rewritten) === 1;
    }

    /**
     * @throws DecryptException
     */
    private static function decrypt(Encrypter $encrypter, string $payload): string
    {
        $plain = $encrypter->decrypt($payload, false);

        return is_string($plain) ? $plain : throw new DecryptException('The value did not decrypt to text.');
    }
}
