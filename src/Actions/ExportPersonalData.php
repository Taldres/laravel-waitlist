<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\Collection;
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
        $query = static::modelClass()::query()->forEmail($email)->within($list, $project);

        /** @var list<PersonalData> $rows */
        $rows = [];

        foreach ($query->with(['subscriptions.consents', 'activity'])->orderBy('created_at')->get() as $entry) {
            $rows[] = PersonalData::fromEntry($entry);
        }

        return new Collection($rows);
    }
}
