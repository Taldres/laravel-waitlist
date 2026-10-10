<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\PackageConfig;
use Taldres\Waitlist\Config\WaitlistConfig;
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
use Taldres\Waitlist\Http\Middleware\CheckRouteConfig;
use Taldres\Waitlist\Http\RouteRegistration;

class WaitlistServiceProvider extends ServiceProvider
{
    /** The limiters config/waitlist.php names by default. */
    public const string SIGNUP_LIMITER = 'waitlist';

    public const string LINKS_LIMITER = 'waitlist-links';

    /**
     * The link limits config/waitlist.php ships, and what stands in for one
     * that does not read: a typo must not take every link down, leaving
     * included, nor leave the links without a limit.
     */
    public const int LINK_PER_MINUTE = 10;

    public const int LINKS_PER_IP_PER_MINUTE = 600;

    public function register(): void
    {
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('waitlist', PackageConfig::merge($config->get('waitlist', [])));
        }

        $this->app->singleton(WaitlistManager::class);
        $this->app->singleton(ProjectDefinitions::class);
        $this->app->scoped(ConfigFallback::class);

        $this->app->bind(EmailNormalizer::class, fn (Application $app): EmailNormalizer => self::make($app, ConfigKey::EmailNormalizer, WaitlistConfig::emailNormalizer(), EmailNormalizer::class));
        $this->app->bind(ConfirmationUrlGenerator::class, fn (Application $app): ConfirmationUrlGenerator => self::make($app, ConfigKey::UrlGenerator, WaitlistConfig::urlGenerator(), ConfirmationUrlGenerator::class));
        $this->app->bind(ProjectCatalog::class, fn (Application $app): ProjectCatalog => self::make($app, ConfigKey::Catalog, WaitlistConfig::catalog(), ProjectCatalog::class));
        $this->app->bind(ProjectResolver::class, fn (Application $app): ProjectResolver => self::make($app, ConfigKey::ProjectResolver, WaitlistConfig::projectResolver(), ProjectResolver::class));
        // A closure from Waitlist::verifySpamUsing() wins over the configured
        // class; bind() re-evaluates on every resolution, so registering in any
        // boot() works regardless of provider order.
        $this->app->bind(SpamProtector::class, fn (Application $app): SpamProtector => $app->make(WaitlistManager::class)->spamProtector()
            ?? self::make($app, ConfigKey::SpamProtector, WaitlistConfig::spamProtector(), SpamProtector::class));
    }

    public function boot(): void
    {
        // An app that defines the ability keeps it, whether its provider boots
        // before this one or after.
        if (! Gate::has(WaitlistGate::ABILITY)) {
            Gate::define(WaitlistGate::ABILITY, WaitlistGate::default(...));
        }

        // Registered even with the package routes off, for your own routes.
        RateLimiter::for(self::SIGNUP_LIMITER, self::signupLimits(...));

        // Per token, not per IP: one-click requests share mail providers' IPs.
        RateLimiter::for(self::LINKS_LIMITER, fn (Request $request): array => [
            Limit::perMinute(ConfigFallback::read(WaitlistConfig::linkPerMinute(...), fallback: self::LINK_PER_MINUTE))
                ->by($request->route()?->uri().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(ConfigFallback::read(WaitlistConfig::linksPerIpPerMinute(...), fallback: self::LINKS_PER_IP_PER_MINUTE))
                ->by(self::linkSource($request).'|links'),
        ]);

        // Laravel sorts the middleware of a route by its priority list, which
        // puts the limiter and the bindings, with "web" the cookies and the
        // session too, ahead of the check. A route that is off must count and
        // set nothing, and a route cache still holds routes the config has
        // since turned off. An app's priority() in bootstrap/app.php is applied
        // when the kernel resolves, before any provider boots, so this goes in
        // front of that list as well.
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if (method_exists($kernel, 'prependToMiddlewarePriority')) {
                $kernel->prependToMiddlewarePriority(CheckRouteConfig::class);
            }
        });

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

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Reported rather than thrown: the app's own tasks must still be
            // scheduled.
            $cron = ConfigFallback::read(WaitlistConfig::pruneSchedule(...), fallback: null);

            if ($cron !== null) {
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
        // Guards that do not read limit the request as a guest's. Only the
        // manage link by token gets past them: the signup and the request by
        // address ask the gate next, which refuses them.
        $caller = ConfigFallback::read(fn (): ?Authenticatable => WaitlistCaller::of($request), fallback: null);

        if (! $caller instanceof HasWaitlistProject) {
            return [Limit::perMinute(WaitlistConfig::signupPerMinute())->by($request->ip().'|'.$endpoint)];
        }

        $server = WaitlistCaller::key($caller);
        $visitor = WaitlistCaller::forwardedIp($request);
        $cap = Limit::perMinute(WaitlistConfig::callerSignupPerMinute())->by("{$server}|{$endpoint}");

        return $visitor === null ? [$cap] : [
            Limit::perMinute(WaitlistConfig::signupPerMinute())->by("{$server}|{$visitor}|{$endpoint}"),
            $cap,
        ];
    }

    /**
     * Guards or a client IP header that do not read key the request by its
     * address, as a guest's: a link must keep working, and a server then
     * shares one key with all of its visitors, which only limits it more.
     */
    private static function linkSource(Request $request): string
    {
        return ConfigFallback::read(function () use ($request): string {
            $caller = WaitlistCaller::of($request);

            if (! $caller instanceof HasWaitlistProject) {
                return (string) $request->ip();
            }

            $visitor = WaitlistCaller::forwardedIp($request);

            return WaitlistCaller::key($caller).($visitor === null ? '' : "|{$visitor}");
        }, fallback: (string) $request->ip());
    }

    /**
     * WaitlistConfig has checked that the class implements the contract. What
     * the container resolves it to, a decorator for example, only has to keep
     * the contract.
     *
     * @template T of object
     *
     * @param  class-string  $class
     * @param  class-string<T>  $contract
     * @return T
     */
    private static function make(Application $app, ConfigKey $key, string $class, string $contract): object
    {
        // This binding is what resolves the contract, so the contract itself
        // would send the container back here until memory runs out. A class
        // that cannot be built is fine only where the app binds it, such as an
        // interface of its own that extends the contract.
        if ($class === $contract) {
            throw new InvalidConfigurationException("The {$key->value} config must name a class that implements {$contract}, not the contract itself.");
        }

        if (! (new ReflectionClass($class))->isInstantiable() && ! $app->bound($class)) {
            throw new InvalidConfigurationException("The {$key->value} config names {$class}, which cannot be built and is not bound in the container. Name a class, or bind {$class} in a service provider.");
        }

        $instance = $app->make($class);

        if (! $instance instanceof $contract) {
            throw new InvalidConfigurationException("The container resolves {$class} to something that is not a {$contract}. Check the {$key->value} config and the binding of {$class}.");
        }

        return $instance;
    }
}
