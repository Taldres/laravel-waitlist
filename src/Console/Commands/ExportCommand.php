<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exports\CsvExporter;
use Taldres\Waitlist\Models\WaitlistEntry;

class ExportCommand extends Command
{
    protected $signature = 'waitlist:export
        {list : The waitlist key to export}
        {--project=default : The project the list belongs to}
        {--status= : Only export entries with this status (pending, confirmed, unsubscribed)}
        {--path= : Target CSV file path (defaults to waitlist-{list}.csv in the current directory)}';

    protected $description = 'Export waitlist entries to a CSV file';

    public function handle(CsvExporter $exporter): int
    {
        $list = $this->argument('list');

        if (! is_string($list) || $list === '') {
            $this->error('A waitlist key is required.');

            return self::FAILURE;
        }

        $status = null;
        $statusOption = $this->option('status');

        if (is_string($statusOption) && $statusOption !== '') {
            $status = EntryStatus::tryFrom($statusOption);

            if (! $status) {
                $this->error("Invalid status [{$statusOption}].");

                return self::FAILURE;
            }
        }

        $pathOption = $this->option('path');
        $path = (is_string($pathOption) && $pathOption !== '')
            ? $pathOption
            : getcwd()."/waitlist-{$list}.csv";

        $project = $this->option('project');

        $rows = $exporter->export(list: $list, status: $status, path: $path, project: is_string($project) && $project !== '' ? $project : WaitlistEntry::DEFAULT_PROJECT);

        $this->info("Exported {$rows} entries to {$path}.");

        return self::SUCCESS;
    }
}
