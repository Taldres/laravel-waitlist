<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Support\OriginPolicy;

/**
 * Global middleware, ahead of Laravel's HandleCors, that gives the CORS
 * settings the origins of the projects for a browser request to the package
 * routes: the paths, the origins and Retry-After, which a client reads after a
 * 429. Where the app's own CORS paths cover the routes already, its origins stay
 * and the projects' are added. A preflight request does not say which project it is for, so every
 * origin is let through here, and the request itself is checked against its
 * project by the useWaitlist gate. The settings are put back afterwards, for the
 * next request of a long-running worker.
 *
 * @internal
 */
final class AllowProjectOrigins
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = $request->headers->has('Origin') ? $this->prefix() : null;

        if ($prefix === null || ! $request->is($prefix, "{$prefix}/*")) {
            return $next($request);
        }

        $origins = ConfigFallback::read(OriginPolicy::all(...), fallback: []);

        if ($origins === []) {
            return $next($request);
        }

        $original = config('cors');

        // An app that covers these paths already decides who else may read them;
        // otherwise Laravel's default of every origin would undo the projects'.
        $covered = $request->is(...$this->merged('paths', []));

        config()->set('cors.paths', $this->merged('paths', [$prefix, "{$prefix}/*"]));
        config()->set('cors.allowed_origins', match (true) {
            ! $covered => $origins,
            in_array('*', $this->merged('allowed_origins', []), true) => ['*'],
            default => $this->merged('allowed_origins', $origins),
        });
        config()->set('cors.exposed_headers', $this->merged('exposed_headers', ['Retry-After']));

        try {
            return $next($request);
        } finally {
            config()->set('cors', $original);
        }
    }

    /**
     * Null where the routes are off or live at the root, which would make the
     * CORS settings apply to the whole app.
     */
    private function prefix(): ?string
    {
        $prefix = ConfigFallback::read(fn (): ?string => WaitlistConfig::routesEnabled() ? trim(WaitlistConfig::routePrefix(), '/') : null, fallback: null);

        return $prefix === '' ? null : $prefix;
    }

    /**
     * What the app's CORS setting holds, with what the projects add.
     *
     * @param  list<string>  $add
     * @return list<string>
     */
    private function merged(string $key, array $add): array
    {
        $existing = config("cors.{$key}", []);

        return array_values(array_unique([...(is_array($existing) ? array_values(array_filter($existing, is_string(...))) : []), ...$add]));
    }
}
