<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * A confirmation was never completed within the retention period. Fired by
 * pruning, immediately before the entry itself is erased.
 */
class SubscriptionExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
    ) {}
}
