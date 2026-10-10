<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Actions\SyncPurposes;
use Taldres\Waitlist\Actions\UnsubscribeEntry;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Http\Controllers\Concerns\RedirectsToFrontend;
use Taldres\Waitlist\Http\Controllers\Concerns\ValidatesAsJson;
use Taldres\Waitlist\Http\Resources\WaitlistEntryResource;
use Taldres\Waitlist\Http\Rules\PurposeChoice;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PersonalData;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * Behind a manage token, which only reaches the mailbox and expires, never
 * the unsubscribe token every mail carries. GET never changes anything.
 */
class ManageController
{
    use RedirectsToFrontend;
    use ResolvesModel;
    use ValidatesAsJson;

    public function show(string $token): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        $entry = static::modelClass()::findByManageToken($token);

        return match (true) {
            $entry === null => $this->redirectFor(Page::Invalid->value) ?? $this->invalid(),
            $entry->hasExpiredManageToken() => $this->redirectFor(Page::Expired->value, $entry) ?? $this->expired(),
            default => $this->redirectFor(Page::Manage->value, $entry, $token) ?? new WaitlistEntryResource($entry),
        };
    }

    /**
     * POST only: a GET would hand the export to every scanner that follows the
     * link.
     */
    public function data(string $token): JsonResponse
    {
        $entry = $this->entry($token);

        if (! $entry instanceof WaitlistEntry) {
            return $entry;
        }

        return new JsonResponse(PersonalData::fromEntry($entry), 200, [
            'Content-Disposition' => 'attachment; filename="waitlist-data.json"',
        ]);
    }

    public function purposes(Request $request, string $token, SyncPurposes $sync): WaitlistEntryResource|JsonResponse
    {
        /** @var array{purposes: array<string, mixed>} $validated */
        $validated = $this->validateAsJson($request, [
            'purposes' => ['required', 'array', 'max:20'],
            'purposes.*' => ['required', new PurposeChoice],
        ]);

        $entry = $this->entry($token);

        if (! $entry instanceof WaitlistEntry) {
            return $entry;
        }

        if ($entry->currentSubscription === null) {
            return new JsonResponse(['message' => 'Not subscribed.'], 409);
        }

        try {
            $entry = $sync($entry, $validated['purposes'], RequestContext::fromRequest($request));
        } catch (MissingConsentException|UnknownPurposeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage(), 'errors' => ['purposes' => [$exception->getMessage()]]], 422);
        } catch (UnknownWaitlistException) {
            // The list was removed from the catalog; leaving still works.
            return new JsonResponse(['message' => 'This list is no longer available.'], 409);
        }

        return new WaitlistEntryResource($entry);
    }

    /**
     * Unlike erasure, keeps the consent records for the configured retention
     * period.
     */
    public function unsubscribe(Request $request, string $token, UnsubscribeEntry $unsubscribe): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        $entry = static::modelClass()::findByManageToken($token);

        if ($entry === null) {
            return $this->redirectFor(Page::Invalid->value) ?? $this->invalid();
        }

        if ($entry->hasExpiredManageToken()) {
            return $this->redirectFor(Page::Expired->value, $entry) ?? $this->expired();
        }

        $entry = $unsubscribe->unsubscribe($entry, RequestContext::fromRequest($request));

        return $this->redirectFor(Page::Unsubscribed->value, $entry) ?? new WaitlistEntryResource($entry);
    }

    public function erase(Request $request, string $token, EraseEntry $erase): JsonResponse|RedirectResponse
    {
        $this->validateAsJson($request, ['confirm' => ['accepted']]);

        $entry = static::modelClass()::findByManageToken($token);

        if ($entry === null) {
            return $this->redirectFor(Page::Invalid->value) ?? $this->invalid();
        }

        if ($entry->hasExpiredManageToken()) {
            return $this->redirectFor(Page::Expired->value, $entry) ?? $this->expired();
        }

        $erase($entry);

        return $this->redirectFor(Page::Erased->value, $entry) ?? new JsonResponse(['message' => 'Erased.']);
    }

    private function entry(string $token): WaitlistEntry|JsonResponse
    {
        $entry = static::modelClass()::findByManageToken($token);

        return match (true) {
            $entry === null => $this->invalid(),
            $entry->hasExpiredManageToken() => $this->expired(),
            default => $entry,
        };
    }

    private function invalid(): JsonResponse
    {
        return new JsonResponse(['message' => 'Invalid token.'], 404);
    }

    private function expired(): JsonResponse
    {
        return new JsonResponse(['message' => 'This link has expired.'], 410);
    }
}
