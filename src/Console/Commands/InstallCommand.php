<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'waitlist:install
        {--projects : Start the provider with several named projects instead of the default one}';

    protected $description = 'Publish the waitlist service provider, config and migrations, and register the provider';

    public function handle(): int
    {
        $projects = (bool) $this->option('projects');
        $provider = $this->laravel->path('Providers/WaitlistServiceProvider.php');
        $existed = is_file($provider);

        if ($projects) {
            $written = $existed || $this->publishProjectsProvider($provider);

            $this->components->task('Publishing the service provider for several projects', fn (): bool => $written);

            if (! $written) {
                $this->components->error('Could not write app/Providers/WaitlistServiceProvider.php; nothing else was published.');

                return self::FAILURE;
            }
        } else {
            $this->components->task('Publishing the service provider', fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'waitlist-provider']) === self::SUCCESS);
        }

        $this->components->task('Publishing the config', fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'waitlist-config']) === self::SUCCESS);
        $this->components->task('Publishing the migrations', fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'waitlist-migrations']) === self::SUCCESS);

        $this->registerProvider();

        if (! $projects || $existed) {
            $this->components->info('Describe your waitlist in app/Providers/WaitlistServiceProvider.php, then run php artisan migrate.');

            if ($projects) {
                $this->components->warn('app/Providers/WaitlistServiceProvider.php already exists and was left as it is. Add your projects to it, see "Several products in one app" in docs/projects.md.');
            }

            return self::SUCCESS;
        }

        $this->components->info('Describe your projects in app/Providers/WaitlistServiceProvider.php, then run php artisan migrate.');
        $this->components->warn('The setup is incomplete until you bind a ProjectResolver in that provider. Until then the package routes, once enabled, act for the default project, which has no lists, so every signup over HTTP answers 422. Your own code names the project with Waitlist::project().');

        return self::SUCCESS;
    }

    /**
     * Copied rather than published under a tag of its own: vendor:publish
     * --provider and --all would publish both stubs to the same path, and with
     * --force this one would replace a provider for one project.
     */
    protected function publishProjectsProvider(string $path): bool
    {
        File::ensureDirectoryExists(dirname($path));

        return File::copy(dirname(__DIR__, 3).'/stubs/WaitlistServiceProvider.projects.stub', $path);
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
