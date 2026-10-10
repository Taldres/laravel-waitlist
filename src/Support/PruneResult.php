<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

final readonly class PruneResult
{
    public function __construct(
        public int $expired,
        public int $erased,
        public int $cleared,
    ) {}
}
