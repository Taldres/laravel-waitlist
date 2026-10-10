<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\SubscriptionExpired;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PruneResult;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

function pendingSince(int $days, string $list = 'beta', string $email = 'user@example.com'): WaitlistEntry
{
    $entry = WaitlistEntry::factory()->pending()->onList($list)->create(['email' => $email]);
    // started_at is immutable on the model, so backdate it in the database.
    $entry->subscriptions()->update(['started_at' => now()->subDays($days)]);

    return $entry;
}

function leftSince(int $days, string $list = 'beta', string $email = 'user@example.com'): WaitlistEntry
{
    $entry = WaitlistEntry::factory()->unsubscribed()->onList($list)->create(['email' => $email]);
    $entry->latestSubscription->forceFill(['ended_at' => now()->subDays($days)])->saveQuietly();

    return $entry;
}

it('expires and erases addresses that never confirmed in time', function () {
    Event::fake([EntryForgotten::class, SubscriptionExpired::class]);

    $stale = pendingSince(40);
    WaitlistEntry::factory()->pending()->create();
    WaitlistEntry::factory()->confirmed()->create();

    expect(app(PruneEntries::class)->expirePending(30))->toBe(1)
        ->and(WaitlistEntry::query()->count())->toBe(2);

    Event::assertDispatchedTimes(SubscriptionExpired::class, 1);
    Event::assertDispatched(EntryForgotten::class, fn (EntryForgotten $event) => $event->entryId === $stale->id);
});

it('records the abandoned signup as expired before erasing it', function () {
    pendingSince(40);

    app(PruneEntries::class)->expirePending(30);

    expect(activityTypes())->toBe([
        ActivityType::Subscribed,
        ActivityType::ConfirmationRequested,
        ActivityType::Expired,
        ActivityType::Erased,
    ])->and(WaitlistActivity::query()->whereNotNull('occurred_at')->count())->toBe(0);
});

it('erases an expired signup on the next run when a listener stopped the last one', function () {
    pendingSince(40);
    $listener = fn () => throw new RuntimeException('Listener down.');
    Event::listen(SubscriptionExpired::class, $listener);

    expect(fn () => app(PruneEntries::class)->expirePending(30))->toThrow(RuntimeException::class);
    expect(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Unsubscribed);

    Event::forget(SubscriptionExpired::class);

    expect(app(PruneEntries::class)->expirePending(30))->toBe(1)
        ->and(WaitlistEntry::query()->count())->toBe(0);
});

it('refuses a negative retention period instead of erasing everything', function () {
    pendingSince(1);
    config()->set(ConfigKey::RetentionPendingDays->value, -1);

    expect(fn () => app(PruneEntries::class)())->toThrow(InvalidArgumentException::class, ConfigKey::RetentionPendingDays->value.' must not be negative.');
    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('counts an abandoned signup as leaving from pending, and its erasure as clean-up', function () {
    pendingSince(40);

    app(PruneEntries::class)->expirePending(30);

    $departures = WaitlistActivity::query()->whereNotNull('previous_status')->orderBy('id')->get()
        ->map(fn (WaitlistActivity $row) => "{$row->type->value} from {$row->previous_status?->value}")->all();

    expect($departures)->toBe(['expired from pending', 'erased from unsubscribed'])
        ->and(Waitlist::for('beta')->report()->totals()->leftConfirmed())->toBe(0);
});

it('measures the age from the current cycle, so a fresh re-subscribe survives', function () {
    $entry = WaitlistEntry::factory()->unsubscribed()->onList('beta')->create(['email' => 'user@example.com']);
    // started_at is immutable on the model, so backdate it in the database.
    $entry->subscriptions()->update([
        'started_at' => now()->subDays(60),
        'confirmed_at' => now()->subDays(59),
        'ended_at' => now()->subDays(50),
    ]);

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect(app(PruneEntries::class)->expirePending(30))->toBe(0)
        ->and(WaitlistEntry::query()->count())->toBe(1);

    assertWaitlistInvariants();
});

it('does not let resends extend the period', function () {
    pendingSince(40);

    config()->set(ConfigKey::ResendCooldown->value, null);
    Waitlist::resendConfirmation('beta', 'user@example.com');

    expect(app(PruneEntries::class)->expirePending(30))->toBe(1);
});

it('lets a confirmation that lands first win over an expiry', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $stale = $tokens['entry']->currentSubscription;

    Waitlist::confirm($tokens['confirm']);

    expect(app(SubscriptionLifecycle::class)->end($stale, EndReason::Expired, RequestContext::none()))->toBeFalse()
        ->and($tokens['entry']->fresh()->status)->toBe(EntryStatus::Confirmed);

    assertWaitlistInvariants();
});

it('erases addresses that left longer ago than the period', function () {
    Event::fake([EntryForgotten::class]);

    $old = leftSince(400);
    leftSince(100, email: 'recent@example.com');
    WaitlistEntry::factory()->confirmed()->create();

    expect(app(PruneEntries::class)->eraseUnsubscribed(365))->toBe(1)
        ->and(WaitlistEntry::query()->count())->toBe(2);

    Event::assertDispatched(EntryForgotten::class, fn (EntryForgotten $event) => $event->entryId === $old->id);
});

it('keeps an address that came back after it was picked for erasure', function () {
    $entry = leftSince(400);
    $picked = WaitlistEntry::query()->leftBefore(365)->sole();

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    $erased = app(EraseEntry::class)($picked, fn (Builder $query) => $query
        ->where('latest_subscription_id', $picked->latest_subscription_id)
        ->leftBefore(365));

    expect($erased)->toBeFalse()
        ->and($entry->fresh()->status)->toBe(EntryStatus::Pending);
});

it('clears request metadata from old log rows only', function () {
    config()->set(ConfigKey::StoreIp->value, true);
    config()->set(ConfigKey::StoreUserAgent->value, true);

    $context = new RequestContext('203.0.113.7', 'TestBrowser');

    $this->travel(-40)->days();
    Waitlist::subscribe('beta', 'old@example.com', waitlistConsent(), context: $context);
    $this->travelBack();

    Waitlist::subscribe('beta', 'new@example.com', waitlistConsent(), context: $context);

    expect(app(PruneEntries::class)->clearRequestMetadata(30))->toBe(2);

    $old = Waitlist::personalData('old@example.com')->sole()->activity;
    $new = Waitlist::personalData('new@example.com')->sole()->activity;

    expect($old[0]->ip)->toBeNull()
        ->and($old[0]->userAgent)->toBeNull()
        ->and($old[0]->occurredAt)->not->toBeNull()
        ->and($new[0]->ip)->toBe('203.0.113.7');
});

it('applies every configured period, each of which can be switched off', function () {
    pendingSince(40);
    leftSince(2000, email: 'gone@example.com');

    expect(app(PruneEntries::class)())->toEqual(new PruneResult(expired: 1, erased: 1, cleared: 0));

    pendingSince(40, email: 'another@example.com');

    config()->set(ConfigKey::RetentionPendingDays->value, null);

    expect(app(PruneEntries::class)()->expired)->toBe(0)
        ->and(WaitlistEntry::query()->count())->toBe(1);
});

it('can be limited to one list', function () {
    pendingSince(40, 'beta');
    pendingSince(40, 'launch');
    leftSince(2000, 'beta', 'gone@example.com');
    leftSince(2000, 'launch', 'gone@example.com');

    expect(app(PruneEntries::class)(list: 'beta'))->toEqual(new PruneResult(expired: 1, erased: 1, cleared: 0))
        ->and(WaitlistEntry::query()->pluck('list')->unique()->all())->toBe(['launch']);
});

it('erases once when two runs reach the same entry', function () {
    Event::fake([EntryForgotten::class]);

    $entry = leftSince(400);
    $stale = WaitlistEntry::query()->findOrFail($entry->id);

    expect(app(EraseEntry::class)($entry))->toBeTrue()
        ->and(app(EraseEntry::class)($stale))->toBeFalse()
        ->and(WaitlistActivity::query()->where('type', ActivityType::Erased)->count())->toBe(1);

    Event::assertDispatchedTimes(EntryForgotten::class, 1);
});

it('erases a whole list once its purpose is fulfilled', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->count(3)->create();
    WaitlistEntry::factory()->confirmed()->onList('launch')->create();

    expect(Waitlist::for('beta')->forgetAll())->toBe(3)
        ->and(WaitlistEntry::query()->pluck('list')->all())->toBe(['launch']);
});
