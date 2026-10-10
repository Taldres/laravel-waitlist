<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Taldres\Waitlist\Exceptions\ManageLinksDisabledException;
use Taldres\Waitlist\WaitlistManager;

class ManageLinkByTokenController
{
    /**
     * With the unsubscribe token from a mail. A token belongs to an entry and
     * its project, so this needs neither the resolver, the gate nor the spam
     * protector, and sits in the links group: a check meant for forms, such as
     * CSRF, must not stop a server that calls with the token.
     *
     * The link goes to the mailbox via ManageLinkRequested, never into this
     * response, which is identical for a token that belongs to nobody.
     */
    public function __invoke(string $token, WaitlistManager $waitlist): JsonResponse
    {
        try {
            $waitlist->requestManageLink($token);
        } catch (ManageLinksDisabledException) {
            return ManageLinkController::disabled();
        }

        return new JsonResponse(['message' => 'Requested.'], 202);
    }
}
