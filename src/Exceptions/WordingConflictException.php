<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

class WordingConflictException extends WaitlistException
{
    public static function changed(string $purpose, string $version, string $locale): self
    {
        $in = $locale === '' ? '' : " in [{$locale}]";

        return new self("The wording of [{$purpose}] version [{$version}] is registered with other text{$in}. Register a new version instead.");
    }

    public static function mixed(string $purpose, string $version): self
    {
        return new self("The wording of [{$purpose}] version [{$version}] holds either one text for every locale or a text per locale, not both.");
    }

    public static function retired(string $purpose, string $version): self
    {
        return new self("The wording of [{$purpose}] version [{$version}] is retired. Register a new version instead.");
    }

    public static function empty(string $purpose, string $version): self
    {
        return new self("The wording of [{$purpose}] version [{$version}] is empty.");
    }
}
