<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Auth;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\HasWaitlistProject;
use Taldres\Waitlist\Enums\WaitlistAction;

/**
 * The useWaitlist gate: whether the caller may sign up, read the wording or
 * request a manage link by address for a project and list. The package defines
 * a default; define the ability yourself to replace it, with any of Laravel's
 * gate responses, such as Response::denyWithStatus(401) or denyAsNotFound().
 */
final class WaitlistGate
{
    public const string ABILITY = 'useWaitlist';

    /**
     * The default: a caller acting for a project keeps to its own. Everyone
     * else may, unless waitlist.authentication.required closes the endpoints
     * to guests (401) and to callers without a project (403). Wording never
     * comes from a guest, who could make a consent say anything.
     */
    public static function default(?Authenticatable $caller, string $project, WaitlistAction $action, ?string $list = null): Response
    {
        if ($action === WaitlistAction::RegisterWording) {
            return $caller instanceof HasWaitlistProject && $caller->waitlistProject() === $project
                ? Response::allow()
                : Response::deny('Only the project\'s own servers may send wording; send the version that was shown.');
        }

        if ($caller instanceof HasWaitlistProject) {
            return $caller->waitlistProject() === $project ? Response::allow() : Response::denyAsNotFound();
        }

        if (! WaitlistConfig::authenticationRequired()) {
            return Response::allow();
        }

        return $caller === null
            ? Response::denyWithStatus(401, 'Unauthenticated.')
            : Response::deny('This caller does not act for a waitlist project.');
    }

    /**
     * Asks the gate for the request's caller; authorize() the result to throw
     * with the status and message the gate chose.
     */
    public static function inspect(Request $request, string $project, WaitlistAction $action, ?string $list = null): Response
    {
        return Gate::forUser(WaitlistCaller::of($request))->inspect(self::ABILITY, [$project, $action, $list]);
    }
}
