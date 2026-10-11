<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Contracts;

use Illuminate\Http\Request;

/**
 * Guards the public endpoints where anyone can type an address (the signup and
 * a manage link requested by address) against bots. Bind your own in a service
 * provider. A closure from Waitlist::verifySpamUsing() takes precedence on
 * those endpoints, while the container still resolves the bound class. Only
 * the HTTP layer consults it, never the actions.
 */
interface SpamProtector
{
    public function passes(Request $request): bool;
}
