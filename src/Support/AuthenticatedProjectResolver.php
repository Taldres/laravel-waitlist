<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Contracts\HasWaitlistProject;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;

/**
 * For a central waitlist API whose clients authenticate: the project is the
 * one the caller acts for (HasWaitlistProject), found through
 * waitlist.authentication.guards. How the caller authenticates, by Sanctum,
 * Passport or a guard of your own, is up to the application.
 */
class AuthenticatedProjectResolver implements ProjectResolver
{
    public function __construct(
        protected ProjectCatalog $catalog,
    ) {}

    public function resolve(Request $request): string
    {
        $caller = WaitlistCaller::of($request);

        if ($caller === null) {
            abort(401, 'Unauthenticated.');
        }

        if (! $caller instanceof HasWaitlistProject) {
            abort(403, 'This caller does not act for a waitlist project.');
        }

        $project = $caller->waitlistProject();

        if (! in_array($project, $this->catalog->projects(), true)) {
            abort(403, 'This project has no waitlist.');
        }

        return $project;
    }
}
