<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Concerns;

use Illuminate\Console\Command;

/**
 * @phpstan-require-extends Command
 */
trait ReadsStringOptions
{
    /**
     * An option that was given with a value; `--list=` counts as not given.
     */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
