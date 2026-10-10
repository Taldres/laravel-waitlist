<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

class EntryConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
        /** Plain unsubscribe token for Waitlist::unsubscribe() and unsubscribe links. */
        public string $unsubscribeToken,
        /** Ready-made unsubscribe link; null when the package routes are disabled and no URL pattern is set. */
        public ?string $unsubscribeUrl,
    ) {}
}
