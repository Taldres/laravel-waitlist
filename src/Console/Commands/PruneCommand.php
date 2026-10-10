<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Taldres\Waitlist\Actions\PruneEntries;

class PruneCommand extends Command
{
    protected $signature = 'waitlist:prune
        {--list= : Only prune this waitlist (of the default project unless --project is given)}
        {--project= : Only prune this project}';

    protected $description = 'Apply the retention periods: erase abandoned signups and addresses that left, clear old request metadata';

    public function handle(PruneEntries $prune): int
    {
        $list = $this->option('list');
        $project = $this->option('project');

        $result = $prune(
            list: is_string($list) && $list !== '' ? $list : null,
            project: is_string($project) && $project !== '' ? $project : null,
        );

        $this->info("Expired and erased {$result->expired} unconfirmed, erased {$result->erased} unsubscribed, cleared request metadata on {$result->cleared} log rows.");

        return self::SUCCESS;
    }
}
