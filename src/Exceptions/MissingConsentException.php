<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class MissingConsentException extends WaitlistException
{
    public static function forPurpose(string $purpose): self
    {
        return new self("Consent to the purpose [{$purpose}] is required.");
    }
}
