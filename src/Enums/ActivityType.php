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
     * reference to what it sent. Not independent delivery verification.
     */
    case ConfirmationMailed = 'confirmation_mailed';

    case Confirmed = 'confirmed';

    case Unsubscribed = 'unsubscribed';

    /** The confirmation was never completed within the retention period. */
    case Expired = 'expired';

    case Erased = 'erased';

    /** An optional purpose was granted within a running cycle. */
    case ConsentGranted = 'consent_granted';

    /** An optional purpose was withdrawn; the cycle continues. */
    case ConsentWithdrawn = 'consent_withdrawn';

    public function isSignup(): bool
    {
        return $this === self::Subscribed || $this === self::Resubscribed;
    }

    /**
     * A step that ends the entry's place on its list. The log keeps the
     * status it left, so a later erasure of someone who had left already
     * does not count as a second departure.
     */
    public function isDeparture(): bool
    {
        return in_array($this, [self::Unsubscribed, self::Expired, self::Erased], true);
    }
}
