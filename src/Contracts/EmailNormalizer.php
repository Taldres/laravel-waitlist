<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Contracts;

/**
 * The one form an address is stored and looked up in. Must be deterministic and
 * idempotent: the entry's email mutator, SubscribeToWaitlist and the blind index
 * all apply it. Changing it requires `php artisan waitlist:rekey` to rebuild the
 * stored lookup hashes.
 */
interface EmailNormalizer
{
    public function normalize(string $email): string;
}
