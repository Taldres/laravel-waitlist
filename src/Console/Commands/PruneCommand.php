<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Console\Concerns\ReadsStringOptions;

#[Signature('waitlist:prune
        {--list= : Only prune this waitlist (of the default project unless --project is given)}
        {--project= : Only prune this project}')]
#[Description('Apply the retention periods: ask confirmed addresses again and end the silent ones, erase abandoned signups and addresses that left, clear old request metadata')]
class PruneCommand extends Command implements Isolatable
{
    use ReadsStringOptions;

    public function handle(PruneEntries $prune): int
    {
        $result = $prune(list: $this->stringOption('list'), project: $this->stringOption('project'));

        $this->info("Expired and erased {$result->expired} unconfirmed, erased {$result->erased} unsubscribed, cleared request metadata on {$result->cleared} log rows, asked {$result->asked} confirmed to confirm again, ended {$result->lapsed} that did not answer.");

        return self::SUCCESS;
    }
}
