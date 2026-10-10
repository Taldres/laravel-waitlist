<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

it('ends the open cycle via the manage token', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Event::fake([EntryUnsubscribed::class]);

    $entry = Waitlist::unsubscribe($tokens['unsubscribe']);
    $cycle = $entry->subscriptions()->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Unsubscribed)
        ->and($cycle->ended_at)->not->toBeNull()
        ->and($cycle->end_reason)->toBe(EndReason::Unsubscribed)
        ->and($cycle->active)->toBeNull()
        ->and($cycle->confirm_token_hash)->toBeNull()
        ->and(activityTypes())->toContain(ActivityType::Unsubscribed);

    Event::assertDispatchedTimes(EntryUnsubscribed::class, 1);
    assertWaitlistInvariants();
});

it('rejects unknown manage tokens', function () {
    Waitlist::unsubscribe('unknown-token');
})->throws(InvalidTokenException::class);

it('is idempotent and fires the event only once', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Event::fake([EntryUnsubscribed::class]);

    Waitlist::unsubscribe($tokens['unsubscribe']);

    expect(Waitlist::unsubscribe($tokens['unsubscribe'])->status)->toBe(EntryStatus::Unsubscribed);

    Event::assertDispatchedTimes(EntryUnsubscribed::class, 1);
});

it('unsubscribes by email through the scoped api', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);

    expect(Waitlist::for('beta')->unsubscribe('user@example.com')->status)->toBe(EntryStatus::Unsubscribed)
        ->and(Waitlist::for('beta')->unsubscribe('nobody@example.com'))->toBeNull();

    assertWaitlistInvariants();
});
