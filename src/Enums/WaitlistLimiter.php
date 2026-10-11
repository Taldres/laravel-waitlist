<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

/**
 * The limiter of each route group, named as the app registers it with
 * RateLimiter::for() to replace it.
 */
enum WaitlistLimiter: string
{
    /** The signup, the wording and a manage link requested by address. */
    case Signup = 'waitlist';

    /** Everything with a token. */
    case Links = 'waitlist-links';

    public function middleware(): string
    {
        return 'throttle:'.$this->value;
    }
}
