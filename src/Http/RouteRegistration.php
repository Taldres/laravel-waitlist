<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http;

use Closure;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Http\Middleware\CheckRouteConfig;

/**
 * Registers the package routes whatever their config says: this runs on
 * every boot, artisan included, and must never stop it.
 *
 * A setting that does not read registers a fallback that only places the
 * routes. The fallback leaves out what the setting meant to add, such as a
 * limiter, so the group is marked and CheckRouteConfig refuses its requests
 * until the routes are registered again, also from a route cache built then.
 *
 * @internal
 */
final class RouteRegistration
{
    /**
     * Whether to register the routes. A switch that does not read registers
     * them as well: CheckRouteConfig then refuses every request.
     */
    public static function wanted(): bool
    {
        return self::read(WaitlistConfig::routesEnabled(...), fallback: true);
    }

    /**
     * @return array{prefix: string, as: string, middleware: list<string>}
     */
    public static function attributes(): array
    {
        return [
            'prefix' => self::read(WaitlistConfig::routePrefix(...), fallback: 'waitlist'),
            'as' => self::read(WaitlistConfig::routeName(...), fallback: 'waitlist.'),
            'middleware' => self::read(WaitlistConfig::routeMiddleware(...), fallback: []),
        ];
    }

    /**
     * The signup, the wording and manage links by address.
     *
     * @return list<string>
     */
    public static function signupMiddleware(): array
    {
        return self::group(CheckRouteConfig::SIGNUP, WaitlistConfig::signupLimiter(...), WaitlistConfig::signupMiddleware(...));
    }

    /**
     * Everything with a token.
     *
     * @return list<string>
     */
    public static function linksMiddleware(): array
    {
        return self::group(CheckRouteConfig::LINKS, WaitlistConfig::linksLimiter(...), WaitlistConfig::linksMiddleware(...));
    }

    /**
     * @param  Closure(): ?string  $limiter
     * @param  Closure(): list<string>  $middleware
     * @return list<string>
     */
    private static function group(string $group, Closure $limiter, Closure $middleware): array
    {
        // The switch and the shared settings count as well: routes registered
        // on an unreadable switch, or placed with a fallback prefix or without
        // their shared middleware, must not serve either.
        $fallback = ! self::reads(
            WaitlistConfig::routesEnabled(...),
            WaitlistConfig::routePrefix(...),
            WaitlistConfig::routeName(...),
            WaitlistConfig::routeMiddleware(...),
            $limiter,
            $middleware,
        );

        // Without a limiter or middleware when they do not read, which the
        // mark above keeps from serving any request.
        $limiterName = self::read($limiter, fallback: null);

        return [
            CheckRouteConfig::using($group, $fallback),
            ...($limiterName === null ? [] : ["throttle:{$limiterName}"]),
            ...self::read($middleware, fallback: []),
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $setting
     * @param  T  $fallback
     * @return T
     */
    private static function read(Closure $setting, mixed $fallback): mixed
    {
        try {
            return $setting();
        } catch (InvalidConfigurationException) {
            return $fallback;
        }
    }

    private static function reads(Closure ...$settings): bool
    {
        try {
            foreach ($settings as $setting) {
                $setting();
            }
        } catch (InvalidConfigurationException) {
            return false;
        }

        return true;
    }
}
