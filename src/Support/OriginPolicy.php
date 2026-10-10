<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * Which websites may use a project's endpoints from a browser. A browser cannot
 * forge its Origin header, a script outside one can, so this keeps other
 * websites and hotlinking out, not bots.
 */
final class OriginPolicy
{
    /**
     * Scheme, host and port the way a browser sends them in Origin: lower case,
     * no path and no default port.
     *
     * @throws InvalidConfigurationException
     */
    public static function normalize(string $origin): string
    {
        $parts = parse_url(trim($origin));

        $valid = is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && isset($parts['host'])
            && array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment'])) === []
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! str_ends_with(trim($origin), '/')
            && ! str_contains($parts['host'], '*');

        if (! $valid) {
            throw new InvalidConfigurationException("An origin must be a scheme and a host, such as https://example.com, without a path or a wildcard, got [{$origin}].");
        }

        /** @var array{scheme: string, host: string, port?: int} $parts */
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.strtolower($parts['host']).($port === null || $port === $default ? '' : ":{$port}");
    }

    /**
     * Whether a request of a guest may act for the project: when the project
     * lists no origins, always; otherwise when the request names no origin, as a
     * server or a same-origin GET does, names the API's own, or names a listed one.
     */
    public static function allows(Request $request, string $project): bool
    {
        $allowed = app(ProjectCatalog::class)->origins($project);
        $origin = $request->headers->get('Origin');

        if ($allowed === [] || $origin === null) {
            return true;
        }

        try {
            $origin = self::normalize($origin);
        } catch (InvalidConfigurationException) {
            return false;
        }

        return $origin === self::normalize($request->getSchemeAndHttpHost()) || in_array($origin, $allowed, true);
    }

    /**
     * The origins of every project, for the answer to a preflight request,
     * which does not say which project it is for.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $catalog = app(ProjectCatalog::class);
        $origins = [];

        foreach ($catalog->projects() as $project) {
            array_push($origins, ...$catalog->origins($project));
        }

        return array_values(array_unique($origins));
    }
}
