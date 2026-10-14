<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Http\Requests\CountRequest;
use Taldres\Waitlist\WaitlistManager;

class CountController
{
    /**
     * Social proof for a waitlist page, only for a list that publishes it,
     * and rounded, so polling it does not reveal single signups.
     */
    public function __invoke(CountRequest $request, WaitlistManager $waitlist, ProjectCatalog $catalog): JsonResponse
    {
        $project = $request->project();
        $list = $request->effectiveList();

        $policy = $catalog->policy($project, $list);

        if ($policy === null) {
            return ApiError::UnknownList->response();
        }

        if (! $policy->publishesCount()) {
            return ApiError::CountDisabled->response();
        }

        return new JsonResponse(['data' => [
            'list' => $list,
            'count' => $waitlist->project($project)->for($list)->publicCount(),
        ]]);
    }
}
