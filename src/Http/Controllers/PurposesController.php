<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Http\Requests\PurposesRequest;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\TimeTrapSpamProtector;
use Taldres\Waitlist\WaitlistManager;

class PurposesController
{
    /**
     * A headless frontend must show exactly this wording: it is what gets
     * stored as consent. With the time trap as the spam protector, the answer
     * also carries the challenge the form sends back with the signup.
     */
    public function __invoke(PurposesRequest $request, WaitlistManager $waitlist, SpamProtector $protector): JsonResponse
    {
        try {
            $purposes = $waitlist->project($request->project())->purposes($request->effectiveList(), $request->locale());
        } catch (UnknownWaitlistException) {
            return ApiError::UnknownList->response();
        }

        $body = ['data' => array_map(fn (PurposeWording $wording) => $wording->toArray(), $purposes)];

        if (! $protector instanceof TimeTrapSpamProtector) {
            return new JsonResponse($body);
        }

        $body['challenge'] = $protector->challenge();

        // A cached challenge would be shared by every visitor and go stale for all at once.
        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store']);
    }
}
