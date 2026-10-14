<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

final readonly class PruneResult
{
    public function __construct(
        public int $expired,
        public int $erased,
        public int $cleared,
        /** Confirmed addresses asked to confirm again. */
        public int $asked = 0,
        /** Confirmed addresses that did not answer in time and left the list. */
        public int $lapsed = 0,
    ) {}
}
