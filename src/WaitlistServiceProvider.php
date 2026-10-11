<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\PackageConfig;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Console\Commands\CheckCommand;
use Taldres\Waitlist\Console\Commands\ExportCommand;
use Taldres\Waitlist\Console\Commands\ForgetCommand;
use Taldres\Waitlist\Console\Commands\InstallCommand;
use Taldres\Waitlist\Console\Commands\PrivacyCommand;
use Taldres\Waitlist\Console\Commands\PruneCommand;
use Taldres\Waitlist\Console\Commands\RekeyCommand;
use Taldres\Waitlist\Console\Commands\ShowCommand;
use Taldres\Waitlist\Console\Commands\WordingCommand;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Http\Limiters;
use Taldres\Waitlist\Http\RouteRegistration;
use Taldres\Waitlist\Support\ReplacedBindings;
use Taldres\Waitlist\Support\SetupAudit;
use Throwable;

class WaitlistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('waitlist', PackageConfig::merge($config->get('waitlist', [])));
        }

        $this->app->singleton(WaitlistManager::class);
        $this->app->singleton(ProjectDefinitions::class);
        $this->app->scoped(ConfigFallback::class);

        // bindIf(): a contract the app or another package has bound already is
        // theirs, and an app's bind() after this one replaces it. A key of an
        // earlier config that still holds more than the default is refused on
        // resolution, never on boot, so artisan keeps working to fix it.
        foreach (ReplacedBindings::KEYS as $key => [$contract, $default]) {
            $this->app->bindIf($contract, static function (Application $app) use ($key, $default): object {
                $refusal = ReplacedBindings::refusal($key);

                return $refusal === null ? $app->make($default) : throw new InvalidConfigurationException($refusal);
            });
        }
    }

    public function boot(): void
    {
        // An app that defines the ability keeps it, whether its provider boots
        // before this one or after.
        if (! Gate::has(WaitlistGate::ABILITY)) {
            Gate::define(WaitlistGate::ABILITY, WaitlistGate::default(...));
        }

        Limiters::register();

        if (RouteRegistration::wanted()) {
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

        AboutCommand::add('Laravel Waitlist', static fn (): array => self::about());

        $this->commands([
            CheckCommand::class,
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
     * The section of `php artisan about`. A row that cannot be read shows as
     * such instead of failing the command; waitlist:check says which setting.
     *
     * @return array<string, string>
     */
    private static function about(): array
    {
        $read = self::readable(...);
        $days = static fn (?int $days): string => $days === null ? 'kept' : "{$days} days";

        return [
            'Version' => $read(fn (): string => InstalledVersions::getPrettyVersion('taldres/laravel-waitlist') ?? 'unknown'),
            'Settings' => $read(fn (): string => ($problems = count(WaitlistConfig::problems())) === 0 ? 'every setting reads' : "{$problems} do not read, run waitlist:check"),
            'Routes' => $read(fn (): string => WaitlistConfig::routesEnabled() ? '/'.trim(WaitlistConfig::routePrefix(), '/') : 'off'),
            'Projects' => $read(fn (): string => implode(', ', app(ProjectCatalog::class)->projects())),
            'Catalog' => $read(fn (): string => app(ProjectCatalog::class)::class),
            'Project resolver' => $read(fn (): string => app(ProjectResolver::class)::class),
            'Spam protector' => $read(fn (): string => app(WaitlistManager::class)->spamProtector()::class),
            'Authentication required' => $read(fn (): string => WaitlistConfig::authenticationRequired() ? 'yes' : 'no'),
            'Double opt-in' => $read(fn (): string => WaitlistConfig::doubleOptIn() ? 'on' : 'off'),
            'Retention' => $read(fn (): string => sprintf('unconfirmed %s, left %s, request metadata %s', $days(WaitlistConfig::pendingDays()), $days(WaitlistConfig::unsubscribedDays()), $days(WaitlistConfig::requestMetadataDays()))),
            'Prune schedule' => $read(fn (): string => SetupAudit::scheduledPrune() ?? 'not scheduled'),
        ];
    }

    /**
     * @param  Closure(): string  $value
     */
    private static function readable(Closure $value): string
    {
        return rescue(
            $value,
            static fn (Throwable $exception): string => $exception instanceof InvalidConfigurationException ? 'does not read' : 'unavailable',
            report: false,
        );
    }
}
