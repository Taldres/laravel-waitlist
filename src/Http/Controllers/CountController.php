<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Http\Controllers\Concerns\ValidatesAsJson;
use Taldres\Waitlist\WaitlistManager;

class CountController
{
    use ValidatesAsJson;

    /**
     * Social proof for a waitlist page, only for a list that publishes it,
     * and rounded, so polling it does not reveal single signups.
     */
    public function __invoke(Request $request, WaitlistManager $waitlist, ProjectResolver $projects, ProjectCatalog $catalog): JsonResponse
    {
        $project = $projects->resolve($request);
        $list = $request->query('list');
        $list = match (true) {
            $list === null || $list === '' => WaitlistConfig::defaultList(),
            is_string($list) => $list,
            default => '',
        };

        WaitlistGate::inspect($request, $project, WaitlistAction::ViewCount, $list)->authorize();

        // The names a signup accepts, so a made-up one never reaches the cache key.
        $this->validateAsJson($request, [
            'list' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:'.ProjectDefinition::NAME_PATTERN],
        ]);

        $policy = $catalog->policy($project, $list);

        if ($policy === null) {
            return new JsonResponse(['message' => 'Not found.', 'error' => ApiError::UnknownList->value], 404);
        }

        if (! $policy->publishesCount()) {
            return new JsonResponse(['message' => 'This list does not publish its count.', 'error' => ApiError::CountDisabled->value], 404);
        }

        return new JsonResponse(['data' => [
            'list' => $list,
            'count' => $waitlist->project($project)->for($list)->publicCount(),
        ]]);
    }
}
