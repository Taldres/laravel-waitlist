<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Support\Carbon;

/**
 * A short-lived token for the preference page: the person's data, purposes and
 * erasure. Only ever send it to the address itself. The URL is null only when
 * the package routes are disabled and no URL pattern is configured.
 */
final readonly class ManageLink
{
    public function __construct(
        public string $token,
        public ?string $url,
        public Carbon $expiresAt,
    ) {}
}
