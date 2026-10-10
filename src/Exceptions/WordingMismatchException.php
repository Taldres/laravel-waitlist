<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class WordingMismatchException extends UnknownPurposeException
{
    public static function forPurpose(string $purpose, string $version): self
    {
        return new self("The wording shown for [{$purpose}] does not match version [{$version}].");
    }

    public static function hashRequired(string $purpose): self
    {
        return new self("The choice for [{$purpose}] needs the hash of the wording shown.");
    }
}
