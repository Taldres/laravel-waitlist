<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Contracts;

use Taldres\Waitlist\Models\WaitlistEntry;

/**
 * Builds the confirm, unsubscribe and manage links your mails carry. Null
 * means no URL: the default gives none when the package routes are disabled and
 * the project has no pattern for the action. The events then carry null, and
 * the link is yours to build from the plain token.
 */
interface ConfirmationUrlGenerator
{
    public function confirmUrl(WaitlistEntry $entry, string $plainToken): ?string;

    public function unsubscribeUrl(WaitlistEntry $entry, string $plainToken): ?string;

    public function manageUrl(WaitlistEntry $entry, string $plainToken): ?string;
}
