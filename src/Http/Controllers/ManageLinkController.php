<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Http\Controllers\Concerns\ValidatesAsJson;
use Taldres\Waitlist\WaitlistManager;

class ManageLinkController
{
    use ValidatesAsJson;

    /**
     * The link goes to the mailbox via ManageLinkRequested, never into this
     * response, which is identical whether or not anything was sent.
     */
    public function __invoke(Request $request, WaitlistManager $waitlist): JsonResponse
    {
        // A token belongs to an entry and its project, so it needs neither the
        // resolver, the gate nor the spam protector; they are resolved below,
        // so their settings cannot stop a request by token.
        if ($request->has('token')) {
            /** @var array{token: string} $validated */
            $validated = $this->validateAsJson($request, ['token' => ['required', 'string', 'max:255']]);

            $waitlist->requestManageLink($validated['token']);

            return new JsonResponse(['message' => 'Requested.'], 202);
        }

        // By address, as on the signup form: the project and the gate come
        // before validation and the spam check.
        $project = app(ProjectResolver::class)->resolve($request);
        $list = $request->input('list');
        $list = is_string($list) ? $list : WaitlistConfig::defaultList();

        WaitlistGate::inspect($request, $project, WaitlistAction::RequestManageLink, $list)->authorize();

        // Without either, the 422 names both alternatives.
        /** @var array{email: string, list?: string} $validated */
        $validated = $this->validateAsJson($request, [
            'token' => ['required_without:email', 'string', 'max:255'],
            'email' => ['required_without:token', 'email:filter', 'max:255'],
            'list' => ['sometimes', 'string', 'max:255'],
        ]);

        // Anyone can type an address here, as on the signup form.
        if (! app(SpamProtector::class)->passes($request)) {
            return new JsonResponse(['message' => 'Spam check failed.'], 422);
        }

        try {
            $waitlist->project($project)->for($list)->requestManageLink($validated['email']);
        } catch (UnknownWaitlistException) {
            // Answered like any other request: the response never tells.
        }

        return new JsonResponse(['message' => 'Requested.'], 202);
    }
}
