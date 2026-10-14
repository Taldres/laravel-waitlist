<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\Collection;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PersonalData;
use Taldres\Waitlist\Support\ResolvesModel;

class ExportPersonalData
{
    use ResolvesModel;

    /**
     * @return Collection<int, PersonalData>
     */
    public function __invoke(string $email, ?string $list = null, ?string $project = null): Collection
    {
        return static::modelClass()::query()
            ->forEmail($email)
            ->within($list, $project)
            ->with(['subscriptions.consents', 'activity'])
            ->orderBy('created_at')
            ->get()
            ->toBase()
            ->map(fn (WaitlistEntry $entry): PersonalData => PersonalData::fromEntry($entry));
    }
}
