<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

/**
 * @internal
 */
final readonly class ExportConfig
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public bool $spreadsheetSafe,
        public array $columns,
    ) {}
}
