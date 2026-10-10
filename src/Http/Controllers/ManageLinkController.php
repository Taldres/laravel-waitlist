<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Http\Controllers\Concerns\ValidatesAsJson;
use Taldres\Waitlist\WaitlistManager;

class ManageLinkController
{
    use ValidatesAsJson;

    /**
     * By address, as on the signup form: the project and the gate come before
     * validation and the spam check. The link goes to the mailbox via
     * ManageLinkRequested, never into this response, which is identical
     * whether or not anything was sent. With a token from a mail, see
     * ManageLinkByTokenController.
     */
    public function __invoke(Request $request, WaitlistManager $waitlist): JsonResponse
    {
        $project = app(ProjectResolver::class)->resolve($request);
        $list = $request->input('list');
        $list = is_string($list) && $list !== '' ? $list : WaitlistConfig::defaultList();

        WaitlistGate::inspect($request, $project, WaitlistAction::RequestManageLink, $list)->authorize();

        // Per project, so the answer says nothing about the address; before the
        // spam check, so no challenge is spent on a request that cannot succeed.
        if (! app(ProjectCatalog::class)->manageLinks($project)) {
            return self::disabled();
        }

        /** @var array{email: string, list?: string} $validated */
        $validated = $this->validateAsJson($request, [
            'email' => ['required', 'email:filter', 'max:255'],
            'list' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Anyone can type an address here, as on the signup form.
        if (! app(SpamProtector::class)->passes($request)) {
            return new JsonResponse(['message' => 'Spam check failed.', 'error' => ApiError::SpamCheckFailed->value], 422);
        }

        try {
            $waitlist->project($project)->for($list)->requestManageLink($validated['email']);
        } catch (UnknownWaitlistException) {
            // Answered like any other request: the response never tells.
        }

        return new JsonResponse(['message' => 'Requested.'], 202);
    }

    public static function disabled(): JsonResponse
    {
        return new JsonResponse(['message' => 'This project offers no manage links.', 'error' => ApiError::ManageLinksDisabled->value], 404);
    }
}
