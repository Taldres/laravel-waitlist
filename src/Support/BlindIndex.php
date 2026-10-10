<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Contracts\Encryption\Encrypter;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\WaitlistManager;

/**
 * Keyed with a subkey of the waitlist encrypter's key. hashes() covers the
 * previous keys, so a rotation keeps every stored address findable.
 */
final class BlindIndex
{
    public static function hash(string $email): string
    {
        return self::hashWith(self::encrypter()->getKey(), $email);
    }

    /**
     * @return list<string>
     */
    public static function hashes(string $email): array
    {
        return array_map(
            fn (string $key): string => self::hashWith($key, $email),
            array_values(array_filter(self::encrypter()->getAllKeys(), is_string(...))),
        );
    }

    private static function encrypter(): Encrypter
    {
        return app(WaitlistManager::class)->encrypter();
    }

    private static function hashWith(string $key, string $email): string
    {
        // A derived subkey, so the encryption key never doubles as a MAC key.
        $subkey = hash_hmac('sha256', 'laravel-waitlist:email', $key, true);

        return hash_hmac('sha256', app(EmailNormalizer::class)->normalize($email), $subkey);
    }
}
