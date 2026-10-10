<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\Setting;
use Taldres\Waitlist\WaitlistManager;

class PurposesController
{
    /**
     * A headless frontend must show exactly this wording: it is what gets
     * stored as consent.
     */
    public function __invoke(Request $request, WaitlistManager $waitlist, ProjectResolver $projects): JsonResponse
    {
        $project = $projects->resolve($request);
        $list = $request->query('list', Setting::string(ConfigKey::DefaultList->value));
        $list = is_string($list) ? $list : '';
        $locale = $request->query('locale');

        WaitlistGate::inspect($request, $project, WaitlistAction::ViewPurposes, $list)->authorize();

        try {
            $purposes = $waitlist->project($project)->purposes($list, is_string($locale) && $locale !== '' ? $locale : null);
        } catch (UnknownWaitlistException) {
            return new JsonResponse(['message' => 'Not found.', 'error' => ApiError::UnknownList->value], 404);
        }

        return new JsonResponse(['data' => array_map(fn (PurposeWording $wording) => $wording->toArray(), $purposes)]);
    }
}
