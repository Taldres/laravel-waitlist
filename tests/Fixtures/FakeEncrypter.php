<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Tests\Fixtures;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use SensitiveParameter;

/**
 * Deliberately not Laravel's payload format.
 */
final class FakeEncrypter implements Encrypter
{
    public function __construct(
        private readonly string $key = 'fake-key',
    ) {}

    public function encrypt(#[SensitiveParameter] mixed $value, mixed $serialize = true): string
    {
        return 'fake.'.base64_encode(strrev((string) $value)).'.'.$this->key;
    }

    public function decrypt(mixed $payload, mixed $unserialize = true): string
    {
        $parts = explode('.', (string) $payload);

        if (count($parts) !== 3 || $parts[0] !== 'fake' || $parts[2] !== $this->key) {
            throw new DecryptException('Not encrypted with this key.');
        }

        return strrev((string) base64_decode($parts[1]));
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getAllKeys(): array
    {
        return [$this->key];
    }

    public function getPreviousKeys(): array
    {
        return [];
    }
}
