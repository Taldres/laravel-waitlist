<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class InvalidEmailException extends WaitlistException
{
    /**
     * Without the address: exception messages reach logs and error trackers,
     * where an erasure cannot reach them.
     */
    public static function make(): self
    {
        return new self('The email address is not valid.');
    }
}
