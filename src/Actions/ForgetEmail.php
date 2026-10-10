<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;

class ForgetEmail
{
    use ResolvesModel;

    public function __construct(
        protected EraseEntry $erase,
    ) {}

    /**
     * GDPR Art. 17. Without $project, a given $list is the default project's
     * list. The model fires EntryForgotten per deleted entry.
     */
    public function __invoke(string $email, ?string $list = null, ?string $project = null): int
    {
        $query = static::modelClass()::query()->forEmail($email)->within($list, $project);

        return $query->get()->filter(fn (WaitlistEntry $entry) => ($this->erase)($entry))->count();
    }
}
