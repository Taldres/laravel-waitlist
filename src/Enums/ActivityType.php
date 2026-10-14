<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

enum ActivityType: string
{
    case Subscribed = 'subscribed';

    case Resubscribed = 'resubscribed';

    /** A confirm token was issued, initially and on every resend. The package sends no mail itself. */
    case ConfirmationRequested = 'confirmation_requested';

    /**
     * Reported by your listener after it mailed the confirmation request, with a
     * reference to what it sent, once per request. Not independent delivery
     * verification.
     */
    case ConfirmationMailed = 'confirmation_mailed';

    /**
     * Reported by your listener when it could not mail the confirmation
     * request, once per request. The request no longer counts against the
     * resend cooldown and the caps, so the person's own retry gets a mail.
     */
    case ConfirmationFailed = 'confirmation_failed';

    case Confirmed = 'confirmed';

    case Unsubscribed = 'unsubscribed';

    /** The confirmation was never completed within the retention period. */
    case Expired = 'expired';

    /**
     * A confirmed entry was asked to confirm again, its period without an
     * answer having passed. The package sends no mail itself.
     */
    case ReconfirmationRequested = 'reconfirmation_requested';

    /** The person answered that request in time; the period starts again. */
    case Reconfirmed = 'reconfirmed';

    /** The request to confirm again went unanswered; the cycle ended. */
    case Lapsed = 'lapsed';

    case Erased = 'erased';

    /** An optional purpose was granted within a running cycle. */
    case ConsentGranted = 'consent_granted';

    /** An optional purpose was withdrawn; the cycle continues. */
    case ConsentWithdrawn = 'consent_withdrawn';

    /** The cycle came from an import; the reference names its source. */
    case Imported = 'imported';

    /**
     * A step that ends the entry's place on its list. The log keeps the
     * status it left, so a later erasure of someone who had left already
     * does not count as a second departure.
     */
    public function isDeparture(): bool
    {
        return in_array($this, [self::Unsubscribed, self::Expired, self::Lapsed, self::Erased], true);
    }
}
