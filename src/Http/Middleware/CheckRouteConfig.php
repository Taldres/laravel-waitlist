<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * The routes are registered whatever their config says, so a mistake in it
 * never stops the app from booting, artisan included. Each request checks
 * the settings of its own group instead: a broken signup group refuses the
 * signup, while the token links keep working.
 *
 * A group RouteRegistration marked as registered with a fallback lacks the
 * middleware or limit its config meant to add, also in a route cache built
 * then. Its requests are refused until the routes are registered again, even
 * once the config reads.
 */
final class CheckRouteConfig
{
    public const string SIGNUP = 'signup';

    public const string LINKS = 'links';

    private const string FALLBACK = 'fallback';

    /**
     * The middleware a group of routes starts with; $fallback marks a group
     * registered while one of its settings did not read.
     */
    public static function using(string $group, bool $fallback = false): string
    {
        return self::class.':'.$group.($fallback ? ','.self::FALLBACK : '');
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $group, ?string $registration = null): Response
    {
        // Routes that are off do not exist, also when a route cache still
        // holds them, and need none of their other settings.
        if (! WaitlistConfig::routesEnabled()) {
            abort(404);
        }

        WaitlistConfig::routePrefix();
        WaitlistConfig::routeName();
        WaitlistConfig::routeMiddleware();

        if ($group === self::SIGNUP) {
            self::definedLimiter(ConfigKey::SignupLimiter, WaitlistConfig::signupLimiter());
            WaitlistConfig::signupMiddleware();
        } else {
            self::definedLimiter(ConfigKey::LinksLimiter, WaitlistConfig::linksLimiter());
            WaitlistConfig::linksMiddleware();
        }

        if ($registration === self::FALLBACK) {
            throw new InvalidConfigurationException('The waitlist routes were registered while their config did not read, so they lack its middleware or limits. Register them again, with php artisan route:cache if the routes are cached, or by restarting long-running workers.');
        }

        return $next($request);
    }

    /**
     * Laravel's throttle throws for a name that no limiter answers to, without
     * naming the key. A number is its own form, throttle:5, and passes. Checked
     * per request: the limiters of the app's providers are not all defined yet
     * when the routes are registered.
     */
    private static function definedLimiter(ConfigKey $key, ?string $name): void
    {
        if ($name !== null && ! is_numeric($name) && RateLimiter::limiter($name) === null) {
            throw new InvalidConfigurationException("The {$key->value} config names the limiter [{$name}], which no RateLimiter::for() defines.");
        }
    }
}
