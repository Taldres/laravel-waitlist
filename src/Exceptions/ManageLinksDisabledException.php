<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class ManageLinksDisabledException extends WaitlistException
{
    public static function forProject(string $project): self
    {
        return new self("The waitlist project [{$project}] offers no manage links.");
    }
}
