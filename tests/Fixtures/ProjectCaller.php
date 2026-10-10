<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Tests\Fixtures;

use Illuminate\Auth\GenericUser;
use Taldres\Waitlist\Contracts\HasWaitlistProject;

/**
 * A project's server holding that project's credentials.
 */
final class ProjectCaller extends GenericUser implements HasWaitlistProject
{
    public function __construct(private readonly string $project)
    {
        parent::__construct(['id' => "project:{$project}"]);
    }

    public function waitlistProject(): string
    {
        return $this->project;
    }
}
