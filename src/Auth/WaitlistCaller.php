<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\HasWaitlistProject;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * Who is calling the signup endpoints: the user of the first guard in
 * waitlist.authentication.guards that authenticates the request, or null for a
 * guest. The project resolver and the useWaitlist gate both ask here, so they
 * always see the same caller. Kept on the request, which never outlives it.
 */
final class WaitlistCaller
{
    private const string ATTRIBUTE = 'waitlist.caller';

    public static function of(Request $request): ?Authenticatable
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            $caller = $request->attributes->get(self::ATTRIBUTE);

            return $caller instanceof Authenticatable ? $caller : null;
        }

        $caller = null;

        foreach (self::guards() as $guard) {
            $user = $request->user(self::defined($guard));

            if ($user instanceof Authenticatable) {
                $caller = $user;

                break;
            }
        }

        $request->attributes->set(self::ATTRIBUTE, $caller);

        return $caller;
    }

    public static function isServer(Request $request): bool
    {
        return self::of($request) instanceof HasWaitlistProject;
    }

    public static function key(Authenticatable $caller): string
    {
        $identifier = $caller->getAuthIdentifier();

        return mb_substr($caller::class.'#'.(is_scalar($identifier) ? (string) $identifier : ''), 0, 255);
    }

    /**
     * Never read from guests, who could send any address, so neither is the
     * setting.
     */
    public static function forwardedIp(Request $request): ?string
    {
        $header = self::isServer($request) ? WaitlistConfig::clientIpHeader() : null;

        if ($header === null) {
            return null;
        }

        $ip = $request->header($header);

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public static function ip(Request $request): ?string
    {
        return self::forwardedIp($request) ?? $request->ip();
    }

    /**
     * Null is the application's default guard.
     *
     * @return list<string|null>
     */
    public static function guards(): array
    {
        return WaitlistConfig::guards();
    }

    /**
     * Laravel refuses a guard it does not define with a bare
     * InvalidArgumentException; this names the setting that asked for it.
     */
    private static function defined(?string $guard): ?string
    {
        $name = $guard ?? config('auth.defaults.guard');

        if (! is_string($name) || config("auth.guards.{$name}") === null) {
            $named = is_string($name) ? $name : 'the default one';

            throw new InvalidConfigurationException("The waitlist.authentication.guards config names a guard config/auth.php does not define: {$named}.");
        }

        return $guard;
    }
}
