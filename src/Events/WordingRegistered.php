<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Taldres\Waitlist\Models\WaitlistWording;

class WordingRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistWording $wording,
    ) {}
}
