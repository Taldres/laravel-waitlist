<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

class EntrySubscribed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
        public ?string $confirmToken,
        /** Plain unsubscribe token for Waitlist::unsubscribe() and unsubscribe links. */
        public string $unsubscribeToken,
        /**
         * From the project's ->urls() or the package routes; null when neither is set,
         * even if confirmation is required.
         */
        public ?string $confirmUrl,
        public ?string $unsubscribeUrl,
        public bool $requiresConfirmation,
        /**
         * False when this is another confirmation request for a cycle that was
         * already running. Check $subscription->sequence === 1 to tell a first
         * signup from someone coming back.
         */
        public bool $isNewCycle,
    ) {}
}
