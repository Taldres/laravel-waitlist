<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting\Concerns;

use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EntryStatus;

trait CountsActivity
{
    /**
     * How often the step happened; with $from, only departures from that
     * status, e.g. erasures of confirmed entries as opposed to the retention
     * clean-up of addresses that had left already.
     */
    public function of(ActivityType $type, ?EntryStatus $from = null): int
    {
        return $from === null
            ? $this->counts[$type->value] ?? 0
            : $this->departures[$type->value][$from->value] ?? 0;
    }

    /**
     * First-time and returning signups together.
     */
    public function signups(): int
    {
        return $this->of(ActivityType::Subscribed) + $this->of(ActivityType::Resubscribed);
    }

    /**
     * An expiry only ever ends an unconfirmed cycle, so it never counts.
     */
    public function leftConfirmed(): int
    {
        return $this->of(ActivityType::Unsubscribed, EntryStatus::Confirmed)
            + $this->of(ActivityType::Erased, EntryStatus::Confirmed);
    }

    public function confirmedChange(): int
    {
        return $this->of(ActivityType::Confirmed) - $this->leftConfirmed();
    }
}
