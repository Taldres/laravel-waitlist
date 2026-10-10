<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Models\WaitlistEntry;

/**
 * Mail $manageUrl to $entry->email and nowhere else: it opens the person's
 * data and erasure until $expiresAt.
 */
class ManageLinkRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public string $manageToken,
        /** From the project's ->urls() or the package routes; null when neither is set. */
        public ?string $manageUrl,
        public Carbon $expiresAt,
    ) {}
}
