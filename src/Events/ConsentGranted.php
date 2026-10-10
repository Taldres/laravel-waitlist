<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

class ConsentGranted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
        public WaitlistConsent $consent,
    ) {}
}
