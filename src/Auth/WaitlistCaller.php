<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\HasWaitlistProject;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Support\Setting;

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
            $user = $request->user($guard);

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
     * Never read from guests, who could send any address.
     */
    public static function forwardedIp(Request $request): ?string
    {
        $header = Setting::value(ConfigKey::ClientIpHeader->value);

        if (! is_string($header) || $header === '' || ! self::isServer($request)) {
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
        $guards = Setting::value(ConfigKey::AuthenticationGuards->value);

        if (! is_array($guards) || array_filter($guards, fn (mixed $guard): bool => $guard !== null && ! is_string($guard)) !== []) {
            throw new InvalidConfigurationException(ConfigKey::AuthenticationGuards->value.' must list guard names, or null for the default guard.');
        }

        /** @var list<string|null> */
        return array_values($guards);
    }
}
