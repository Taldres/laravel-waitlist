<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Taldres\Waitlist\Events\EntrySubscribed;

#[Signature('waitlist:install
        {--projects : Start the provider with several named projects instead of the default one}
        {--mail : Also write the confirmation mail: a mailable, its view and a queued listener for EntrySubscribed}')]
#[Description('Publish the waitlist service provider, config and migrations, register the provider, and optionally scaffold the confirmation mail')]
class InstallCommand extends Command
{
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

        if ($this->option('mail') && ! $this->scaffoldMail()) {
            return self::FAILURE;
        }

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
     * Written once and owned by the app afterwards, so an existing file is
     * left as it is. Laravel discovers the listener in app/Listeners by the
     * type hint of handle(); a second listener for the event would send every
     * mail twice, so --mail replaces an existing confirmation listener rather
     * than adding one.
     *
     * A wildcard listener (Telescope registers one) does not count: only a
     * listener registered for the event itself.
     *
     * @return bool false when a file could not be written
     */
    protected function scaffoldMail(): bool
    {
        $namespace = Str::replaceLast('\\', '', $this->laravel->getNamespace());
        $listener = $this->laravel->path('Listeners/SendWaitlistConfirmationMail.php');

        $files = [
            'ConfirmWaitlistMail' => [$this->laravel->path('Mail/ConfirmWaitlistMail.php'), 'app/Mail/ConfirmWaitlistMail.php'],
            'SendWaitlistConfirmationMail' => [$listener, 'app/Listeners/SendWaitlistConfirmationMail.php'],
            'confirm.blade' => [$this->laravel->resourcePath('views/mail/waitlist/confirm.blade.php'), 'resources/views/mail/waitlist/confirm.blade.php'],
        ];

        $written = [];

        foreach ($files as $stub => [$path, $label]) {
            if (is_file($path)) {
                $this->components->warn("{$label} already exists and was left as it is.");

                continue;
            }

            if ($path === $listener && (Event::getRawListeners()[EntrySubscribed::class] ?? []) !== []) {
                $this->components->warn("EntrySubscribed already has a listener, so {$label} was not written: --mail replaces an existing confirmation listener, it does not add a second one. Send app/Mail/ConfirmWaitlistMail.php from your own listener, or remove that listener and run this again.");

                continue;
            }

            File::ensureDirectoryExists(dirname($path));

            $contents = str_replace('{{ namespace }}', $namespace, (string) file_get_contents(dirname(__DIR__, 3)."/stubs/mail/{$stub}.stub"));
            $ok = File::put($path, $contents) !== false;

            $this->components->task("Writing {$label}", fn (): bool => $ok);

            if (! $ok) {
                $this->components->error("Could not write {$label}.");

                return false;
            }

            $written[] = $path;
        }

        if (in_array($listener, $written, true) && ! in_array($this->laravel->path('Mail/ConfirmWaitlistMail.php'), $written, true)) {
            $this->components->warn('The listener was written next to a ConfirmWaitlistMail that already existed: check that its constructor takes the confirm URL, the unsubscribe URL and the list of wordings.');
        }

        if ($written !== []) {
            $this->components->info('The confirmation mail is yours now: review its text and sender, and set the confirm page in urls() or enable the package routes. See docs/mail.md.');
        }

        return true;
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
