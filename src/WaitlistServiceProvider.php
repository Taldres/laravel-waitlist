<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Console\Commands\ExportCommand;
use Taldres\Waitlist\Console\Commands\ForgetCommand;
use Taldres\Waitlist\Console\Commands\InstallCommand;
use Taldres\Waitlist\Console\Commands\PrivacyCommand;
use Taldres\Waitlist\Console\Commands\PruneCommand;
use Taldres\Waitlist\Console\Commands\RekeyCommand;
use Taldres\Waitlist\Console\Commands\ShowCommand;
use Taldres\Waitlist\Console\Commands\WordingCommand;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\HasWaitlistProject;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Support\Setting;

class WaitlistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/waitlist.php', 'waitlist');

        $this->app->singleton(WaitlistManager::class);
        $this->app->singleton(ProjectDefinitions::class);

        $this->app->bind(EmailNormalizer::class, fn (Application $app): EmailNormalizer => self::implementation($app, ConfigKey::EmailNormalizer->value, EmailNormalizer::class));
        $this->app->bind(ConfirmationUrlGenerator::class, fn (Application $app): ConfirmationUrlGenerator => self::implementation($app, ConfigKey::UrlGenerator->value, ConfirmationUrlGenerator::class));
        $this->app->bind(ProjectCatalog::class, fn (Application $app): ProjectCatalog => self::implementation($app, ConfigKey::Catalog->value, ProjectCatalog::class));
        $this->app->bind(ProjectResolver::class, fn (Application $app): ProjectResolver => self::implementation($app, ConfigKey::ProjectResolver->value, ProjectResolver::class));
        // A closure from Waitlist::verifySpamUsing() wins over the configured
        // class; bind() re-evaluates on every resolution, so registering in any
        // boot() works regardless of provider order.
        $this->app->bind(SpamProtector::class, fn (Application $app): SpamProtector => $app->make(WaitlistManager::class)->spamProtector()
            ?? self::implementation($app, ConfigKey::SpamProtector->value, SpamProtector::class));
    }

    public function boot(): void
    {
        // An app that defines the ability keeps it, whether its provider boots
        // before this one or after.
        if (! Gate::has(WaitlistGate::ABILITY)) {
            Gate::define(WaitlistGate::ABILITY, WaitlistGate::default(...));
        }

        // Registered even with the package routes off, for your own routes.
        RateLimiter::for('waitlist', self::signupLimits(...));

        // Per token, not per IP: one-click requests share mail providers' IPs.
        RateLimiter::for('waitlist-links', fn (Request $request): array => [
            Limit::perMinute(Setting::integer(ConfigKey::LinkPerMinute->value))
                ->by($request->route()?->uri().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(Setting::integer(ConfigKey::LinksPerIpPerMinute->value))
                ->by(self::linkSource($request).'|links'),
        ]);

        if (Setting::enabled(ConfigKey::RoutesEnabled->value)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/waitlist.php');
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/waitlist.php' => config_path('waitlist.php'),
        ], ['waitlist', 'waitlist-config']);

        $this->publishes([
            __DIR__.'/../stubs/WaitlistServiceProvider.stub' => app_path('Providers/WaitlistServiceProvider.php'),
        ], ['waitlist', 'waitlist-provider']);

        // Numbered file names keep the published order: each table references
        // the ones before it.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['waitlist', 'waitlist-migrations']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = Setting::value(ConfigKey::RetentionSchedule->value);

            if (is_string($cron) && $cron !== '') {
                $schedule->command(PruneCommand::class)->cron($cron)->withoutOverlapping();
            }
        });

        $this->commands([
            ExportCommand::class,
            ForgetCommand::class,
            InstallCommand::class,
            PrivacyCommand::class,
            PruneCommand::class,
            RekeyCommand::class,
            ShowCommand::class,
            WordingCommand::class,
        ]);
    }

    /**
     * A server's visitors share its address, so the server is capped as a
     * whole and limits them itself, unless it forwards their address.
     *
     * @return list<Limit>
     */
    private static function signupLimits(Request $request): array
    {
        $endpoint = (string) $request->route()?->uri();
        $caller = WaitlistCaller::of($request);

        if (! $caller instanceof HasWaitlistProject) {
            return [Limit::perMinute(Setting::integer(ConfigKey::SignupPerMinute->value))->by($request->ip().'|'.$endpoint)];
        }

        $server = WaitlistCaller::key($caller);
        $visitor = WaitlistCaller::forwardedIp($request);
        $cap = Limit::perMinute(Setting::integer(ConfigKey::CallerSignupPerMinute->value))->by("{$server}|{$endpoint}");

        return $visitor === null ? [$cap] : [
            Limit::perMinute(Setting::integer(ConfigKey::SignupPerMinute->value))->by("{$server}|{$visitor}|{$endpoint}"),
            $cap,
        ];
    }

    private static function linkSource(Request $request): string
    {
        $caller = WaitlistCaller::of($request);

        if (! $caller instanceof HasWaitlistProject) {
            return (string) $request->ip();
        }

        $visitor = WaitlistCaller::forwardedIp($request);

        return WaitlistCaller::key($caller).($visitor === null ? '' : "|{$visitor}");
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private static function implementation(Application $app, string $key, string $contract): object
    {
        $implementation = $app->make(config()->string($key));

        if (! $implementation instanceof $contract) {
            throw new InvalidConfigurationException("The {$key} config must point to a {$contract} implementation.");
        }

        return $implementation;
    }
}
