<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * A confirmed address did not answer the request to confirm again in time
 * and left the list. Fired by pruning; the entry is kept like one that
 * unsubscribed, until retention.unsubscribed_days erases it. Stop mailing it
 * wherever you sync to, as on EntryUnsubscribed.
 */
class SubscriptionLapsed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
    ) {}
}
