<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class WordingNotAcceptedException extends UnknownPurposeException
{
    public static function fromClient(string $purpose): self
    {
        return new self("The wording of [{$purpose}] comes from the catalog here; send the version that was shown, without its text.");
    }
}
