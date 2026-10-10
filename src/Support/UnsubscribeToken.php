<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

/**
 * The entry's unsubscribe token and its URL. The token can only remove:
 * unsubscribe, withdraw a purpose, or ask for a manage link to be mailed. The
 * URL is null only when the package routes are disabled and no URL pattern is
 * configured; build it from the plain token yourself in that case.
 */
final readonly class UnsubscribeToken
{
    public function __construct(
        public string $token,
        public ?string $url,
    ) {}

    /**
     * The query goes ahead of a fragment, where a page that keeps the token out
     * of server logs carries it.
     */
    public static function withPurpose(string $url, string $purpose): string
    {
        $hash = strpos($url, '#');
        $fragment = $hash === false ? '' : substr($url, $hash);
        $url = $hash === false ? $url : substr($url, 0, $hash);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['purpose' => $purpose]).$fragment;
    }
}
