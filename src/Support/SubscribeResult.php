<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

final readonly class SubscribeResult
{
    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
        public SubscribeOutcome $outcome,
    ) {}

    public function startedCycle(): bool
    {
        return $this->outcome->startedCycle();
    }
}
