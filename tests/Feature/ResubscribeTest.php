<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

it('opens a second cycle after someone left, keeping the first intact', function () {
    $entry = WaitlistEntry::factory()->unsubscribed()->onList('beta')->create([
        'email' => 'user@example.com',
    ]);
    $unsubscribeHash = $entry->unsubscribe_token_hash;

    $result = Waitlist::subscribe('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);

    $cycles = $result->entry->subscriptions()->with('consents')->get();

    expect($result->outcome)->toBe(SubscribeOutcome::Resubscribed)
        ->and($result->entry->status)->toBe(EntryStatus::Pending)
        ->and($cycles)->toHaveCount(2)
        ->and($cycles[0]->consents->pluck('purpose')->all())->toBe(['waitlist'])
        ->and($cycles[0]->isOpen())->toBeFalse()
        ->and($cycles[1]->consents->pluck('purpose')->all())->toBe(['waitlist', 'newsletter'])
        ->and($cycles[1]->sequence)->toBe(2)
        ->and($cycles[1]->isOpen())->toBeTrue();

    assertWaitlistInvariants();
});

it('keeps the manage token, so unsubscribe links from the first cycle still work', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Waitlist::unsubscribe($tokens['unsubscribe']);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect(Waitlist::unsubscribe($tokens['unsubscribe'])->status)->toBe(EntryStatus::Unsubscribed);

    assertWaitlistInvariants();
});

it('logs the second signup as a resubscribe', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::unsubscribe($tokens['unsubscribe']);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect(activityTypes())->toBe([
        ActivityType::Subscribed,
        ActivityType::ConfirmationRequested,
        ActivityType::Unsubscribed,
        ActivityType::Resubscribed,
        ActivityType::ConfirmationRequested,
    ]);
});

it('puts the purposes of the newest cycle in force once it is confirmed', function () {
    $entry = WaitlistEntry::factory()->unsubscribed(['waitlist', 'newsletter'])->onList('beta')->create([
        'email' => 'user@example.com',
    ]);

    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    expect($entry->fresh()->purposes)->toBe(['waitlist']);
});

it('tells listeners and the caller about the new cycle, with the metadata it brought', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);

    WaitlistEntry::factory()->unsubscribed()->onList('beta')->create([
        'email' => 'user@example.com',
        'metadata' => ['source' => 'landing'],
    ]);

    $heard = null;
    Event::listen(EntryConfirmed::class, function (EntryConfirmed $event) use (&$heard) {
        $heard = [$event->entry->purposes, $event->entry->unsubscribed_at, $event->entry->metadata];
    });

    $result = Waitlist::subscribe('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10'], ['source' => 'webinar']);

    expect($heard)->toBe([['waitlist', 'newsletter'], null, ['source' => 'webinar']])
        ->and($result->entry->purposes)->toBe(['waitlist', 'newsletter'])
        ->and($result->entry->latestSubscription?->sequence)->toBe(2);
});
