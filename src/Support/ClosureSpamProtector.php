<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Closure;
use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\SpamProtector;

final class ClosureSpamProtector implements SpamProtector
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
