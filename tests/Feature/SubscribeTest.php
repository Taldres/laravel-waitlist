<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

it('starts a first cycle for a new address', function () {
    $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect($result->outcome)->toBe(SubscribeOutcome::Started)
        ->and($result->entry->status)->toBe(EntryStatus::Pending)
        ->and($result->subscription->sequence)->toBe(1)
        ->and($result->subscription->consents->pluck('purpose')->all())->toBe(['waitlist'])
        ->and($result->subscription->confirmation_count)->toBe(1)
        ->and($result->subscription->isOpen())->toBeTrue();

    expect(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::ConfirmationRequested]);

    assertWaitlistInvariants();
});

it('normalizes the email and rejects invalid ones', function () {
    $result = Waitlist::subscribe('beta', '  USER@Example.COM ', waitlistConsent());

    expect($result->entry->email)->toBe('user@example.com');

    Waitlist::subscribe('beta', 'not-an-email', waitlistConsent());
})->throws(InvalidEmailException::class);

it('keeps the address out of the invalid email message, which ends up in logs', function () {
    expect(fn () => Waitlist::subscribe('beta', 'jane.doe@', waitlistConsent()))
        ->toThrow(fn (InvalidEmailException $exception) => expect($exception->getMessage())->not->toContain('jane.doe'));
});

it('confirms immediately when double opt-in is off', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);
    Event::fake([EntrySubscribed::class, EntryConfirmed::class]);

    $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    // Listeners that sync or welcome on confirmation must see it too.
    Event::assertDispatched(EntrySubscribed::class, fn (EntrySubscribed $event) => ! $event->requiresConfirmation);
    Event::assertDispatched(EntryConfirmed::class, fn (EntryConfirmed $event) => $event->entry->is($result->entry)
        && $event->unsubscribeToken === $result->entry->plainUnsubscribeToken());

    expect($result->entry->status)->toBe(EntryStatus::Confirmed)
        ->and($result->subscription->confirmed_at)->not->toBeNull()
        ->and($result->subscription->confirm_token_hash)->toBeNull()
        ->and($result->subscription->confirmation_count)->toBe(0);

    expect(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::Confirmed]);

    assertWaitlistInvariants();
});

it('respects per-list double opt-in overrides', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('instant', purpose: 'waitlist')->doubleOptIn(false));

    expect(Waitlist::subscribe('instant', 'user@example.com', waitlistConsent())->entry->status)->toBe(EntryStatus::Confirmed)
        ->and(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->entry->status)->toBe(EntryStatus::Pending);
});

it('does nothing for an address that already confirmed', function () {
    $entry = WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);

    Event::fake([EntrySubscribed::class]);

    $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect($result->outcome)->toBe(SubscribeOutcome::AlreadyConfirmed)
        ->and($result->entry->id)->toBe($entry->id)
        ->and(WaitlistEntry::query()->count())->toBe(1);

    Event::assertNotDispatched(EntrySubscribed::class);
    assertWaitlistInvariants();
});

it('treats a pending address as a resend request without recording consent again', function () {
    $entry = WaitlistEntry::factory()->pending()->onList('beta')->create([
        'email' => 'user@example.com',
    ]);
    $unsubscribeHash = $entry->unsubscribe_token_hash;
    $startedAt = $entry->currentSubscription->started_at;

    $this->travel(10)->minutes();

    $result = Waitlist::subscribe('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);

    expect($result->outcome)->toBe(SubscribeOutcome::ConfirmationResent)
        ->and($result->entry->subscriptions()->count())->toBe(1)
        ->and($result->subscription->consents->pluck('purpose')->all())->toBe(['waitlist'])
        ->and($result->subscription->confirmation_count)->toBe(2)
        ->and($result->subscription->started_at->equalTo($startedAt))->toBeTrue()
        ->and($result->entry->unsubscribe_token_hash)->toBe($unsubscribeHash);

    assertWaitlistInvariants();
});

it('suppresses a repeat inside the resend cooldown', function () {
    WaitlistEntry::factory()->pending()->onList('beta')->create(['email' => 'user@example.com']);

    Event::fake([EntrySubscribed::class]);

    $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect($result->outcome)->toBe(SubscribeOutcome::ResendSuppressed)
        ->and($result->subscription->confirmation_count)->toBe(1);

    Event::assertNotDispatched(EntrySubscribed::class);
});

it('takes metadata only when a cycle starts, since a repeat proves nothing about the mailbox', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', metadata: ['source' => 'landing']);

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), metadata: ['source' => 'forged', 'utm' => 'x']);
    Waitlist::confirm($tokens['confirm']);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), metadata: ['source' => 'forged']);

    expect(WaitlistEntry::query()->firstOrFail()->metadata)->toBe(['source' => 'landing']);

    Waitlist::unsubscribe($tokens['unsubscribe']);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), metadata: ['utm' => 'comeback']);

    // Key order is not preserved by every JSON column, so compare by key.
    expect(WaitlistEntry::query()->firstOrFail()->metadata)
        ->toHaveCount(2)
        ->toMatchArray(['source' => 'landing', 'utm' => 'comeback']);
});

it('caps how many lists an address can be waiting on, so made-up list names cannot flood a mailbox', function () {
    config()->set(ConfigKey::MaxPendingPerAddress->value, 2);
    Event::fake([EntrySubscribed::class]);

    $first = Waitlist::subscribe('one', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('two', 'USER@example.com', waitlistConsent());
    $third = Waitlist::subscribe('three', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('four', 'other@example.com', waitlistConsent());

    expect($third->outcome)->toBe(SubscribeOutcome::ConfirmationDeferred)
        ->and($third->startedCycle())->toBeTrue()
        ->and($third->subscription->confirmation_count)->toBe(0)
        ->and($third->subscription->confirm_token_hash)->toBeNull()
        ->and($third->entry->status)->toBe(EntryStatus::Pending);

    Event::assertDispatchedTimes(EntrySubscribed::class, 3);

    expect(Waitlist::for('three')->resendConfirmation('user@example.com'))->toBeNull();

    $token = null;
    Event::assertDispatched(EntrySubscribed::class, function (EntrySubscribed $event) use ($first, &$token) {
        return $event->entry->is($first->entry) && ($token = $event->confirmToken) !== null;
    });
    Waitlist::confirm($token);

    expect(Waitlist::subscribe('three', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::ConfirmationResent);
    Event::assertDispatchedTimes(EntrySubscribed::class, 4);
    // The held-back request is the cycle's first, not a reminder.
    Event::assertDispatched(EntrySubscribed::class, fn (EntrySubscribed $event) => $event->entry->is($third->entry) && $event->isNewCycle);
    assertWaitlistInvariants();
});

it('counts only the last day\'s requests towards the cap, so nobody can lock an address out for long', function () {
    config()->set(ConfigKey::MaxPendingPerAddress->value, 2);

    Waitlist::subscribe('one', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('two', 'user@example.com', waitlistConsent());

    $this->travel(25)->hours();

    expect(Waitlist::subscribe('three', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::Started);
});

it('keeps lists apart', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('launch', 'user@example.com', waitlistConsent());

    expect(WaitlistEntry::query()->count())->toBe(2);
    assertWaitlistInvariants();
});

it('accepts any list through a "*" entry and rejects unknown lists without one', function () {
    expect(Waitlist::subscribe('anything', 'user@example.com', waitlistConsent())->entry->list)->toBe('anything');

    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'), lists: false);

    expect(Waitlist::subscribe('beta', 'other@example.com', waitlistConsent())->entry->list)->toBe('beta');

    Waitlist::subscribe('secret', 'third@example.com', waitlistConsent());
})->throws(UnknownWaitlistException::class);
