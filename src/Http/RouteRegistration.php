<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http;

use Closure;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * Decides which package routes are registered. This runs on every boot,
 * artisan included, and must never stop it: a setting that does not read is
 * reported, once, and what it belongs to is not registered, rather than
 * routes that lack what the setting meant to add. The other group stays, so a
 * broken signup limiter does not take the unsubscribe links with it.
 * waitlist:check names every setting that does not read.
 *
 * A route cache holds the routes as they were when it was built; after a
 * change of these settings, build it again.
 *
 * @internal
 */
final class RouteRegistration
{
    /**
     * Whether the routes are on and the settings that place them read.
     */
    public static function wanted(): bool
    {
        try {
            if (! WaitlistConfig::routesEnabled()) {
                return false;
            }

            self::attributes();
        } catch (InvalidConfigurationException $exception) {
            app(ConfigFallback::class)->report($exception);

            return false;
        }

        return true;
    }

    /**
     * @return array{prefix: string, as: string, middleware: list<string>}
     */
    public static function attributes(): array
    {
        return [
            'prefix' => WaitlistConfig::routePrefix(),
            'as' => WaitlistConfig::routeName(),
            'middleware' => WaitlistConfig::routeMiddleware(),
        ];
    }

    /**
     * The middleware of the signup, the wording and manage links by address;
     * null when the group's settings do not read.
     *
     * @return list<string>|null
     */
    public static function signupMiddleware(): ?array
    {
        return self::group(WaitlistConfig::signupLimiter(...), WaitlistConfig::signupMiddleware(...));
    }

    /**
     * The middleware of everything with a token; null when the group's
     * settings do not read.
     *
     * @return list<string>|null
     */
    public static function linksMiddleware(): ?array
    {
        return self::group(WaitlistConfig::linksLimiter(...), WaitlistConfig::linksMiddleware(...));
    }

    /**
     * @param  Closure(): ?string  $limiter
     * @param  Closure(): list<string>  $middleware
     * @return list<string>|null
     */
    private static function group(Closure $limiter, Closure $middleware): ?array
    {
        try {
            $name = $limiter();

            return [...($name === null ? [] : ["throttle:{$name}"]), ...$middleware()];
        } catch (InvalidConfigurationException $exception) {
            app(ConfigFallback::class)->report($exception);

            return null;
        }
    }
}
