<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\RequestContext;

it('erases the address and its cycles across all lists', function () {
    Event::fake([EntryForgotten::class]);

    $beta = WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('launch')->create(['email' => 'user@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'other@example.com']);

    $deleted = Waitlist::forget('user@example.com');

    expect($deleted)->toBe(2)
        ->and(WaitlistEntry::query()->pluck('email')->all())->toBe(['other@example.com'])
        ->and(WaitlistSubscription::query()->count())->toBe(1);

    Event::assertDispatchedTimes(EntryForgotten::class, 2);
    Event::assertDispatched(EntryForgotten::class, fn (EntryForgotten $event) => $event->entryId === $beta->id
        && $event->email === 'user@example.com'
        && $event->list === 'beta');
});

it('erases on a single list only', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('launch')->create(['email' => 'user@example.com']);

    expect(Waitlist::forget('user@example.com', list: 'beta'))->toBe(1)
        ->and(WaitlistEntry::query()->pluck('list')->all())->toBe(['launch']);
});

it('strips every identifying field from the log but keeps the counts', function () {
    config()->set(ConfigKey::StoreIp->value, true);
    config()->set(ConfigKey::StoreUserAgent->value, true);

    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm'], new RequestContext('127.0.0.1', 'TestBrowser'));

    $before = WaitlistActivity::query()->count();

    Waitlist::forget('user@example.com');

    $rows = WaitlistActivity::query()->orderBy('id')->get();

    expect($rows)->toHaveCount($before + 1) // plus the erasure itself
        ->and($rows->whereNotNull('waitlist_entry_id'))->toBeEmpty()
        ->and($rows->whereNotNull('waitlist_subscription_id'))->toBeEmpty()
        ->and($rows->whereNotNull('occurred_at'))->toBeEmpty()
        ->and($rows->whereNotNull('ip'))->toBeEmpty()
        ->and($rows->whereNotNull('user_agent'))->toBeEmpty()
        ->and($rows->whereNull('occurred_on'))->toBeEmpty()
        ->and($rows->pluck('list')->unique()->all())->toBe(['beta'])
        ->and($rows->last()->type)->toBe(ActivityType::Erased);
});

it('strips the keys from the log where the database does not enforce them', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Only SQLite lets a test switch foreign keys off.');
    }

    DB::statement('PRAGMA foreign_keys = OFF');
    subscribeAndCapture('beta', 'user@example.com');

    Waitlist::forget('user@example.com');

    expect(WaitlistActivity::query()->whereNotNull('waitlist_entry_id')->orWhereNotNull('waitlist_subscription_id')->exists())->toBeFalse();
});

it('reports nothing to erase for an unknown address', function () {
    Event::fake([EntryForgotten::class]);

    expect(Waitlist::forget('nobody@example.com'))->toBe(0);

    Event::assertNotDispatched(EntryForgotten::class);
});

it('announces an erasure for a plain model delete too', function () {
    Event::fake([EntryForgotten::class]);

    WaitlistEntry::factory()->confirmed()->create()->delete();

    Event::assertDispatchedTimes(EntryForgotten::class, 1);
});
