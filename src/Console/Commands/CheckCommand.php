<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Support\SetupAudit;
use Throwable;

class CheckCommand extends Command
{
    protected $signature = 'waitlist:check
        {--strict : Fail on warnings too}';

    protected $description = 'Check the settings and the tables, for a deploy';

    public function handle(): int
    {
        $failed = false;
        $warned = false;

        $problems = WaitlistConfig::problems();

        if ($problems === []) {
            $this->components->info('Every setting reads.');
        }

        foreach ($problems as $problem) {
            $this->components->error($problem->getMessage());
            $failed = true;
        }

        foreach (SetupAudit::unknownSettings() as $setting) {
            $this->components->warn("{$setting} is not a setting the package reads; check it for a typo, or remove it from config/waitlist.php and its variable from .env.");
            $warned = true;
        }

        try {
            $missing = SetupAudit::missingSchema();
        } catch (Throwable $exception) {
            $this->components->warn('The database could not be read ('.$exception::class.'), so the tables were not checked.');

            return $failed || ($warned && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
        }

        if ($missing === []) {
            $this->components->info('The tables and columns of this version exist.');
        }

        foreach ($missing as $table => $columns) {
            $this->components->error($columns === null
                ? "The table {$table} does not exist; publish and run the package migrations."
                : "The table {$table} lacks the columns ".implode(', ', $columns).'; run the package migrations, or add the columns in a migration of your own.');
            $failed = true;
        }

        return $failed || ($warned && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
