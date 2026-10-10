<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class InvalidTokenException extends WaitlistException
{
    public static function make(): self
    {
        return new self('The provided waitlist token is invalid.');
    }
}
