<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

/**
 * @internal
 */
final readonly class RetentionConfig
{
    /**
     * Periods in days; null keeps.
     */
    public function __construct(
        public ?int $pendingDays,
        public ?int $unsubscribedDays,
        public ?int $requestMetadataDays,
    ) {}
}
