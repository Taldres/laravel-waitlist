<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\Carbon;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Reporting\Period;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;

class RecordActivity
{
    use ResolvesModel;

    public function __invoke(
        WaitlistEntry $entry,
        ActivityType $type,
        ?WaitlistSubscription $subscription = null,
        ?RequestContext $context = null,
        ?string $purpose = null,
        ?string $reference = null,
        ?EntryStatus $previousStatus = null,
    ): WaitlistActivity {
        $now = Carbon::now();

        return static::activityModelClass()::query()->create([
            'waitlist_entry_id' => $entry->getKey(),
            'waitlist_subscription_id' => $subscription?->getKey(),
            'project' => $entry->project,
            'list' => $entry->list,
            'type' => $type,
            'previous_status' => $previousStatus,
            'purpose' => $purpose,
            'reference' => $reference,
            'occurred_at' => $now,
            'occurred_on' => static::dateFor($now),
        ] + ($context ?? RequestContext::none())->stored());
    }

    /**
     * Strips every identifying field from the entry's history, including the
     * exact time: a second-precision timestamp can still single out a person.
     * The keys are cleared here too rather than left to the database, which
     * does not enforce them everywhere (SQLite without foreign keys, for one).
     */
    public function erased(WaitlistEntry $entry): void
    {
        $model = static::activityModelClass();

        $model::query()->create([
            'waitlist_entry_id' => $entry->getKey(),
            'project' => $entry->project,
            'list' => $entry->list,
            'type' => ActivityType::Erased,
            'previous_status' => $entry->status,
            'occurred_at' => null,
            'occurred_on' => static::dateFor(Carbon::now()),
        ]);

        $model::query()
            ->where('waitlist_entry_id', $entry->getKey())
            ->update([
                'waitlist_entry_id' => null,
                'waitlist_subscription_id' => null,
                'ip' => null,
                'user_agent' => null,
                'occurred_at' => null,
                'reference' => null,
            ]);
    }

    /**
     * Reports group on this stored date, which keeps them free of
     * engine-specific date functions and independent of the timezone the query
     * runs in.
     */
    public static function dateFor(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(Period::timezone())->toDateString();
    }
}
