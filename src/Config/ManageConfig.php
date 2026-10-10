<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

/**
 * @internal
 */
final readonly class ManageConfig
{
    /**
     * @param  int  $tokenTtl  minutes a manage link works
     * @param  int|null  $requestCooldown  minutes between two manage link mails to one address; null none
     */
    public function __construct(
        public int $tokenTtl,
        public ?int $requestCooldown,
    ) {}
}
