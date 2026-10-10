<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;

class EraseEntry
{
    use ResolvesModel;

    /**
     * The row is locked and re-read first, so racing erasures record and
     * announce it once and $stillApplies checks current data.
     *
     * @param  (Closure(Builder<WaitlistEntry>): Builder<WaitlistEntry>)|null  $stillApplies
     */
    public function __invoke(WaitlistEntry $entry, ?Closure $stillApplies = null): bool
    {
        return static::waitlistConnection()->transaction(function () use ($entry, $stillApplies): bool {
            $query = static::modelClass()::query()->whereKey($entry->getKey());

            $locked = ($stillApplies === null ? $query : $stillApplies($query))->lockForUpdate()->first();

            return $locked !== null && $locked->delete() === true;
        });
    }
}
