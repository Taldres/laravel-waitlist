<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * A confirmed address is asked whether it still wants to be on the list.
 * Mail $confirmUrl to $entry->email: posting its token back before
 * $answerBy keeps the address; without an answer, waitlist:prune ends the
 * cycle as lapsed. Fired by waitlist:prune, once per request.
 */
class ReconfirmationRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public WaitlistEntry $entry,
        public WaitlistSubscription $subscription,
        /** A confirm token for the cycle; it answers this request and nothing else. */
        public string $confirmToken,
        /** From the project's ->urls() or the package routes; null when neither is set. */
        public ?string $confirmUrl,
        /** Plain unsubscribe token for Waitlist::unsubscribe() and unsubscribe links. */
        public string $unsubscribeToken,
        public ?string $unsubscribeUrl,
        /** When the link stops working and the address lapses at the next run of waitlist:prune. */
        public Carbon $answerBy,
    ) {}
}
