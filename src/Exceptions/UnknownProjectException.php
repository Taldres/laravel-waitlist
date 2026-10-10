<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class UnknownProjectException extends UnknownWaitlistException
{
    public static function forProject(string $project): self
    {
        return new self("The project [{$project}] is not configured.");
    }
}
