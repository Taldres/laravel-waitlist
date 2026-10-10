<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

/**
 * @internal
 */
final readonly class PrivacyConfig
{
    public function __construct(
        public bool $storeIp,
        public bool $storeUserAgent,
    ) {}
}
