<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
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
    public function __invoke(Request $request, WaitlistManager $waitlist, ProjectResolver $projects): JsonResponse
    {
        $project = $projects->resolve($request);
        $list = $request->query('list');
        $list = match (true) {
            $list === null || $list === '' => WaitlistConfig::defaultList(),
            is_string($list) => $list,
            default => '',
        };
        $locale = $request->query('locale');

        WaitlistGate::inspect($request, $project, WaitlistAction::ViewPurposes, $list)->authorize();

        try {
            $purposes = $waitlist->project($project)->purposes($list, is_string($locale) && $locale !== '' ? $locale : null);
        } catch (UnknownWaitlistException) {
            return new JsonResponse(['message' => 'Not found.', 'error' => ApiError::UnknownList->value], 404);
        }

        $body = ['data' => array_map(fn (PurposeWording $wording) => $wording->toArray(), $purposes)];
        $protector = $waitlist->spamProtector();

        if (! $protector instanceof TimeTrapSpamProtector) {
            return new JsonResponse($body);
        }

        $body['challenge'] = $protector->challenge();

        // A cached challenge would be shared by every visitor and go stale for all at once.
        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store']);
    }
}
