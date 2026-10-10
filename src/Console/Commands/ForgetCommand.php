<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Taldres\Waitlist\Actions\ForgetEmail;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\ScopedWaitlist;

class ForgetCommand extends Command
{
    protected $signature = 'waitlist:forget
        {email? : The email address to erase}
        {--list= : Only erase from this waitlist (of the default project unless --project is given)}
        {--project= : Only erase from this project}
        {--all : Erase every entry on --list instead of one address}
        {--force : Erase every entry on --list without asking}';

    protected $description = 'Permanently delete all entries for an email address, or a whole list (right to erasure)';

    public function handle(ForgetEmail $forget): int
    {
        $email = $this->argument('email');
        $list = $this->option('list');
        $project = $this->option('project');
        $project = is_string($project) && $project !== '' ? $project : null;

        $list = is_string($list) && $list !== '' ? $list : null;

        if ($this->option('all')) {
            if (is_string($email) && $email !== '') {
                $this->error('--all erases a whole list; leave out the email address.');

                return self::FAILURE;
            }

            return $this->forgetList($list ?? '', $project ?? WaitlistEntry::DEFAULT_PROJECT);
        }

        if (! is_string($email) || $email === '') {
            $this->error('An email address is required.');

            return self::FAILURE;
        }

        $deleted = $forget(email: $email, list: $list, project: $project);

        $where = match (true) {
            $list !== null => ' on '.($project ?? WaitlistEntry::DEFAULT_PROJECT)."/{$list}",
            $project !== null => " in project {$project}",
            default => '',
        };

        $this->info("Deleted {$deleted} entries for {$email}{$where}.");

        return self::SUCCESS;
    }

    protected function forgetList(string $list, string $project): int
    {
        if ($list === '') {
            $this->error('--all needs --list.');

            return self::FAILURE;
        }

        $label = $project === WaitlistEntry::DEFAULT_PROJECT ? $list : "{$project}/{$list}";

        // Without a terminal nobody can answer, and silence is no consent.
        if (! $this->option('force') && ! ($this->input->isInteractive() && $this->confirm("Permanently erase every entry on [{$label}]?"))) {
            $this->error('Nothing erased. Pass --force to erase without asking.');

            return self::FAILURE;
        }

        // Not through the facade: a project missing from the catalog still has data to erase.
        $this->info('Deleted '.(new ScopedWaitlist($list, $project))->forgetAll()." entries from {$label}.");

        return self::SUCCESS;
    }
}
