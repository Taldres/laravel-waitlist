<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

it('holds EntrySubscribed until the surrounding transaction commits', function () {
    Event::fake([EntrySubscribed::class]);

    DB::transaction(function () {
        Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

        Event::assertNotDispatched(EntrySubscribed::class);
    });

    Event::assertDispatchedTimes(EntrySubscribed::class, 1);
});

it('discards the event and the entry when the surrounding transaction rolls back', function () {
    Event::fake([EntrySubscribed::class]);

    try {
        DB::transaction(function () {
            Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(EntrySubscribed::class);
    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('discards a confirm and its event on rollback', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Event::fake([EntryConfirmed::class]);

    try {
        DB::transaction(function () use ($tokens) {
            Waitlist::confirm($tokens['confirm']);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(EntryConfirmed::class);
    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);
});

it('discards an unsubscribe and its event on rollback', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Event::fake([EntryUnsubscribed::class]);

    try {
        DB::transaction(function () use ($tokens) {
            Waitlist::unsubscribe($tokens['unsubscribe']);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(EntryUnsubscribed::class);
    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);
});

it('carries the cycle on every lifecycle event', function () {
    $seen = [];

    foreach ([EntrySubscribed::class, EntryConfirmed::class, EntryUnsubscribed::class] as $event) {
        Event::listen($event, function ($e) use (&$seen) {
            $seen[$e::class] = $e->subscription->sequence;
        });
    }

    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);
    Waitlist::unsubscribe($tokens['unsubscribe']);

    expect($seen)->toBe([
        EntrySubscribed::class => 1,
        EntryConfirmed::class => 1,
        EntryUnsubscribed::class => 1,
    ]);
});
