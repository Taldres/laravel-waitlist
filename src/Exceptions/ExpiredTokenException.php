<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class ExpiredTokenException extends WaitlistException
{
    public static function make(): self
    {
        return new self('The provided waitlist token has expired.');
    }
}
