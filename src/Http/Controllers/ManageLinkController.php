<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Http\Requests\ManageLinkRequest;
use Taldres\Waitlist\WaitlistManager;

class ManageLinkController
{
    /**
     * By address, as on the signup form: the project, the gate and the
     * project's manage-link switch come before validation and the spam check,
     * see ManageLinkRequest. The link goes to the mailbox via
     * ManageLinkRequested, never into this response, which is identical
     * whether or not anything was sent. With a token from a mail, see
     * ManageLinkByTokenController.
     */
    public function __invoke(ManageLinkRequest $request, WaitlistManager $waitlist, SpamProtector $spam): JsonResponse
    {
        // Anyone can type an address here, as on the signup form.
        if (! $spam->passes($request)) {
            return ApiError::SpamCheckFailed->response();
        }

        try {
            $waitlist->project($request->project())->for($request->effectiveList())->requestManageLink($request->safe()->string('email')->value());
        } catch (UnknownWaitlistException) {
            // Answered like any other request: the response never tells.
        }

        return new JsonResponse(['message' => 'Requested.'], 202);
    }
}
