<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\WaitlistManager;

class PositionController
{
    public const int CACHE_SECONDS = 60;

    /**
     * By the unsubscribe token only, never by address, so nobody can be
     * looked up. The entry's own project and list apply, whatever the request
     * names.
     */
    public function __invoke(string $token, WaitlistManager $waitlist, ProjectCatalog $catalog): JsonResponse
    {
        $entry = $waitlist->findByUnsubscribeToken($token);

        if ($entry === null) {
            return new JsonResponse(['message' => 'Invalid token.', 'error' => ApiError::InvalidToken->value], 404);
        }

        if ($catalog->policy($entry->project, $entry->list)?->publishesPosition() !== true) {
            return new JsonResponse(['message' => 'This list does not publish positions.', 'error' => ApiError::PositionDisabled->value], 404);
        }

        $position = $entry->status === EntryStatus::Confirmed ? $this->position($waitlist, $entry) : null;

        return new JsonResponse(['data' => [
            'project' => $entry->project,
            'list' => $entry->list,
            'status' => $entry->status->value,
            'position' => $position,
        ]]);
    }

    /**
     * Counting everyone ahead reads the whole list, so a token checked again
     * and again is answered from the cache for a minute. Keyed by the cycle as
     * well, so someone who leaves and comes back is not shown their old place.
     */
    protected function position(WaitlistManager $waitlist, WaitlistEntry $entry): ?int
    {
        $key = 'waitlist:position:'.hash('sha256', (string) json_encode([$entry->getKey(), $entry->latest_subscription_id]));
        // Wrapped, as some stores hand a plain number back as a string.
        $cached = Cache::get($key);

        if (is_array($cached) && is_int($cached['position'] ?? null)) {
            return $cached['position'];
        }

        $position = $waitlist->project($entry->project)->for($entry->list)->position($entry);

        if ($position !== null) {
            Cache::put($key, ['position' => $position], self::CACHE_SECONDS);
        }

        return $position;
    }
}
