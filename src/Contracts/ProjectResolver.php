<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Contracts;

use Illuminate\Http\Request;

/**
 * Which project a request to the signup endpoints acts for: the signup itself,
 * the wording for the form, and a manage link requested by address. Token links
 * need no resolver, since a token already belongs to an entry and its project.
 *
 * The default always answers "default". Throw an HttpException, e.g.
 * abort(401), to reject a request.
 */
interface ProjectResolver
{
    public function resolve(Request $request): string;
}
