<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

final readonly class RekeyResult
{
    public function __construct(
        public int $entries,
        public int $activity,
        public int $unreadable,
        public int $duplicates,
    ) {}
}
