<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http;

use Closure;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionException;
use ReflectionProperty;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\HasWaitlistProject;
use Taldres\Waitlist\Enums\WaitlistLimiter;

/**
 * The two limiters of the route groups. An app replaces one by defining a
 * limiter of the same name.
 *
 * @internal
 */
final class Limiters
{
    /**
     * What stands in for a link limit that does not read: a typo must not take
     * every link down, leaving included, nor leave the links without a limit.
     */
    public const int LINK_PER_MINUTE = 10;

    public const int LINKS_PER_IP_PER_MINUTE = 600;

    /** @var array<string, Closure> */
    private static array $limiters = [];

    /**
     * Registered even with the package routes off, for your own routes. A
     * limiter of the same name that the app defines keeps its place, as the
     * gate does.
     */
    public static function register(): void
    {
        foreach (WaitlistLimiter::cases() as $limiter) {
            self::define($limiter, match ($limiter) {
                WaitlistLimiter::Signup => self::signup(...),
                WaitlistLimiter::Links => self::links(...),
            });
        }
    }

    /**
     * Whether the limiter now stored under the name is still the package's own:
     * an app that defines the name after the package booted replaces it.
     */
    public static function owns(WaitlistLimiter $limiter): bool
    {
        // RateLimiter::limiter() wraps what it returns anew on every call, so
        // only the stored closure can be compared with the one registered.
        try {
            $stored = (new ReflectionProperty(CacheRateLimiter::class, 'limiters'))->getValue(app(CacheRateLimiter::class));
        } catch (ReflectionException) {
            return false;
        }

        return isset(self::$limiters[$limiter->value]) && is_array($stored) && ($stored[$limiter->value] ?? null) === self::$limiters[$limiter->value];
    }

    private static function define(WaitlistLimiter $limiter, Closure $limits): void
    {
        if (RateLimiter::limiter($limiter->value) === null) {
            self::$limiters[$limiter->value] = $limits;
            RateLimiter::for($limiter->value, $limits);
        }
    }

    /**
     * A server's visitors share its address, so the server is capped as a
     * whole and limits them itself, unless it forwards their address.
     *
     * @return list<Limit>
     */
    private static function signup(Request $request): array
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
     * Per token, not per IP: one-click requests share mail providers' IPs.
     *
     * @return list<Limit>
     */
    private static function links(Request $request): array
    {
        return [
            Limit::perMinute(ConfigFallback::read(WaitlistConfig::linkPerMinute(...), fallback: self::LINK_PER_MINUTE))
                ->by($request->route()?->uri().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(ConfigFallback::read(WaitlistConfig::linksPerIpPerMinute(...), fallback: self::LINKS_PER_IP_PER_MINUTE))
                ->by(self::source($request).'|links'),
        ];
    }

    /**
     * Guards or a client IP header that do not read key the request by its
     * address, as a guest's: a link must keep working, and a server then
     * shares one key with all of its visitors, which only limits it more.
     */
    private static function source(Request $request): string
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
}
