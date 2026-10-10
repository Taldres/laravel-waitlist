<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

/**
 * Wording comes from the waitlist_wordings table, where the frontend or CMS
 * that shows it registers it (waitlist:wording, Waitlist::registerWording())
 * or a project's servers send it with signups (wordingFromCallers()); the
 * purposes a definition names with purpose() are ignored. Everything else
 * comes from Waitlist::define(). Versions are ordered by first registration.
 */
class StoredWordingCatalog extends DefinedProjectCatalog
{
    public function versions(string $project, string $purpose): array
    {
        return $this->stored($project)[$purpose] ?? [];
    }
}
