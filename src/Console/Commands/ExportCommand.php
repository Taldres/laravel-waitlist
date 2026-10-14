<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Taldres\Waitlist\Console\Concerns\ReadsStringOptions;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exports\CsvExporter;
use Taldres\Waitlist\Models\WaitlistEntry;

#[Signature('waitlist:export
        {list : The waitlist key to export}
        {--project=default : The project the list belongs to}
        {--status= : Only export entries with this status (pending, confirmed, unsubscribed)}
        {--path= : Target CSV file path (defaults to waitlist-{list}.csv in the current directory)}')]
#[Description('Export waitlist entries to a CSV file')]
class ExportCommand extends Command implements PromptsForMissingInput
{
    use ReadsStringOptions;

    public function handle(CsvExporter $exporter): int
    {
        $list = $this->argument('list');

        if (! is_string($list) || $list === '') {
            $this->fail('A waitlist key is required.');
        }

        $status = null;
        $statusOption = $this->stringOption('status');

        if ($statusOption !== null) {
            $status = EntryStatus::tryFrom($statusOption);

            if (! $status) {
                $this->fail("Invalid status [{$statusOption}].");
            }
        }

        $path = $this->stringOption('path') ?? getcwd()."/waitlist-{$list}.csv";

        $rows = $exporter->export(list: $list, status: $status, path: $path, project: $this->stringOption('project') ?? WaitlistEntry::DEFAULT_PROJECT);

        $this->info("Exported {$rows} entries to {$path}.");

        return self::SUCCESS;
    }
}
