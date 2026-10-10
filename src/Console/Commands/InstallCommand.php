<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'waitlist:install';

    protected $description = 'Publish the waitlist service provider, config and migrations, and register the provider';

    public function handle(): int
    {
        $this->components->task('Publishing the service provider', fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'waitlist-provider']) === self::SUCCESS);
        $this->components->task('Publishing the config', fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'waitlist-config']) === self::SUCCESS);
        $this->components->task('Publishing the migrations', fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'waitlist-migrations']) === self::SUCCESS);

        $this->registerProvider();

        $this->components->info('Describe your waitlist in app/Providers/WaitlistServiceProvider.php, then run php artisan migrate.');

        return self::SUCCESS;
    }

    /**
     * The stub is written for the App namespace; an app with its own keeps it.
     */
    protected function registerProvider(): void
    {
        $path = $this->laravel->path('Providers/WaitlistServiceProvider.php');

        if (! is_file($path)) {
            $this->components->warn('No app/Providers/WaitlistServiceProvider.php to register.');

            return;
        }

        $namespace = Str::replaceLast('\\', '', $this->laravel->getNamespace());

        file_put_contents($path, str_replace('namespace App\Providers;', "namespace {$namespace}\\Providers;", (string) file_get_contents($path)));

        if (! ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\WaitlistServiceProvider", $this->laravel->getBootstrapProvidersPath())) {
            $this->components->warn("Register {$namespace}\\Providers\\WaitlistServiceProvider in your application's providers yourself.");
        }
    }
}
