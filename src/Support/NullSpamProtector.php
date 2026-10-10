<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\SpamProtector;

class NullSpamProtector implements SpamProtector
{
    public function passes(Request $request): bool
    {
        return true;
    }
}
