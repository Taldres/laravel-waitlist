<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Actions\SyncPurposes;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

/**
 * The database picks the statement as a deadlock victim once, as a server
 * does when two requests lock in crossing order; the step must retry from
 * fresh data and act once.
 */
function deadlockOnce(string $pattern): void
{
    $thrown = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$thrown, $pattern): void {
        if (! $thrown && preg_match($pattern, $query) === 1) {
            $thrown = true;

            throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        }
    });
}

it('retries a resubscribe that lost a deadlock and starts one cycle', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::unsubscribe($tokens['unsubscribe']);
    Event::fake([EntrySubscribed::class]);

    deadlockOnce('/^insert into \W?waitlist_activity/i');

    expect(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::Resubscribed)
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending)
        ->and(array_count_values(array_map(fn (ActivityType $type) => $type->value, activityTypes()))[ActivityType::Resubscribed->value])->toBe(1);

    Event::assertDispatchedTimes(EntrySubscribed::class, 1);
    assertWaitlistInvariants();
});

it('retries a transition that lost a deadlock and announces it once', function (string $event, string $pattern, Closure $step, EntryStatus $status) {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Event::fake([$event]);

    deadlockOnce($pattern);

    $step($tokens);

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe($status);
    Event::assertDispatchedTimes($event, 1);
    assertWaitlistInvariants();
})->with([
    'confirm' => [EntryConfirmed::class, '/^insert into \W?waitlist_activity/i', fn (array $tokens) => Waitlist::confirm($tokens['confirm']), EntryStatus::Confirmed],
    'unsubscribe' => [EntryUnsubscribed::class, '/^insert into \W?waitlist_activity/i', fn (array $tokens) => Waitlist::unsubscribe($tokens['unsubscribe']), EntryStatus::Unsubscribed],
]);

it('retries an erasure that lost a deadlock and erases once', function () {
    subscribeAndCapture('beta', 'user@example.com');
    Event::fake([EntryForgotten::class]);

    deadlockOnce('/^delete from \W?waitlist_entries/i');

    expect(Waitlist::forget('user@example.com'))->toBe(1)
        ->and(WaitlistEntry::query()->count())->toBe(0);

    Event::assertDispatchedTimes(EntryForgotten::class, 1);
});

it('retries a preference change that lost a deadlock and leaves the purposes submitted', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($tokens['confirm']);
    Event::fake([ConsentWithdrawn::class]);

    deadlockOnce('/^update \W?waitlist_consents/i');

    $entry = app(SyncPurposes::class)(WaitlistEntry::query()->firstOrFail(), waitlistConsent());

    expect($entry->hasConsentFor('newsletter'))->toBeFalse()
        ->and($entry->hasConsentFor('waitlist'))->toBeTrue();

    Event::assertDispatchedTimes(ConsentWithdrawn::class, 1);
    assertWaitlistInvariants();
});
