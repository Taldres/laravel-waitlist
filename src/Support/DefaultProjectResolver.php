<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Models\WaitlistEntry;

class DefaultProjectResolver implements ProjectResolver
{
    public function resolve(Request $request): string
    {
        return WaitlistEntry::DEFAULT_PROJECT;
    }
}
