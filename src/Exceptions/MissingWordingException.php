<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

/**
 * A list's primary purpose has no wording in force, as with StoredWordingCatalog
 * before the first waitlist:wording, or after every version was retired.
 */
class MissingWordingException extends InvalidConfigurationException
{
    public static function forPurpose(string $project, string $list, string $purpose): self
    {
        return new self("The purpose [{$purpose}] of the waitlist [{$project}/{$list}] has no wording.");
    }
}
