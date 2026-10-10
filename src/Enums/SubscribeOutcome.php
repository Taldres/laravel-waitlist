<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

enum SubscribeOutcome: string
{
    /** A first cycle for a new address. */
    case Started = 'started';

    case Resubscribed = 'resubscribed';

    /** The address was already waiting; another confirmation request went out. */
    case ConfirmationResent = 'confirmation_resent';

    /** The address was already waiting, but the resend cooldown or cap applied. */
    case ResendSuppressed = 'resend_suppressed';

    /** The address is already confirmed; nothing happened. */
    case AlreadyConfirmed = 'already_confirmed';

    /**
     * A cycle started, but its confirmation request was held back because the
     * address already waits on max_pending_per_address lists of the project. A
     * later signup or resend sends it once one is settled.
     */
    case ConfirmationDeferred = 'confirmation_deferred';

    public function startedCycle(): bool
    {
        return in_array($this, [self::Started, self::Resubscribed, self::ConfirmationDeferred], true);
    }
}
