<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

/**
 * How confirm links are issued: how long they work and how often they go
 * out. Confirming an issued link needs none of it.
 *
 * @internal
 */
final readonly class ConfirmationConfig
{
    /**
     * @param  int|null  $tokenTtl  minutes a confirm link works; null never expires
     * @param  int|null  $resendCooldown  minutes between two requests of one cycle; null none
     * @param  int|null  $maxConfirmations  requests per cycle; null no cap
     * @param  int|null  $maxPendingPerAddress  requests per address and day for a project's unconfirmed lists; null no cap
     */
    public function __construct(
        public ?int $tokenTtl,
        public ?int $resendCooldown,
        public ?int $maxConfirmations,
        public ?int $maxPendingPerAddress,
    ) {}
}
