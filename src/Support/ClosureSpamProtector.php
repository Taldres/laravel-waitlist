<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Closure;
use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\SpamProtector;

/**
 * Checks the request with a closure, for a check that is a few lines long:
 * bind it with `$this->app->bind(SpamProtector::class, fn () => new ClosureSpamProtector(...))`.
 */
class ClosureSpamProtector implements SpamProtector
{
    /**
     * @param  Closure(Request): bool  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function passes(Request $request): bool
    {
        return (bool) ($this->callback)($request);
    }
}
