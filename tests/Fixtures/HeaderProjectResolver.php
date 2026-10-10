<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Tests\Fixtures;

use Illuminate\Http\Request;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;

class HeaderProjectResolver implements ProjectResolver
{
    public function __construct(
        protected ProjectCatalog $catalog,
    ) {}

    public function resolve(Request $request): string
    {
        $project = $request->header('X-Waitlist-Project');

        return is_string($project) && in_array($project, $this->catalog->projects(), true) ? $project : abort(401);
    }
}
