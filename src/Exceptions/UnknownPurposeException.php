<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class UnknownPurposeException extends WaitlistException
{
    public static function forPurpose(string $list, string $purpose): self
    {
        return new self("The purpose [{$purpose}] is not configured for the waitlist [{$list}].");
    }

    public static function forVersion(string $purpose, string $version): self
    {
        return new self("The version [{$version}] of the purpose [{$purpose}] is not available.");
    }

    public static function forLocale(string $purpose, string $version, ?string $locale): self
    {
        return new self($locale === null
            ? "The version [{$version}] of the purpose [{$purpose}] needs the locale its wording was shown in."
            : "The version [{$version}] of the purpose [{$purpose}] has no wording in [{$locale}].");
    }
}
