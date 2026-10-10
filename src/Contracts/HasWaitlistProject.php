<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Contracts;

/**
 * An authenticated caller that acts for one project, such as the server of a
 * product's landing page holding that product's API token. The default
 * useWaitlist gate keeps it to its own project, and AuthenticatedProjectResolver
 * takes the project from it.
 */
interface HasWaitlistProject
{
    public function waitlistProject(): string;
}
