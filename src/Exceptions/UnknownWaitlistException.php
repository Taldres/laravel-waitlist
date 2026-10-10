<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class UnknownWaitlistException extends WaitlistException
{
    public static function forList(string $project, string $list): self
    {
        return new self("The waitlist [{$project}/{$list}] is not configured.");
    }
}
