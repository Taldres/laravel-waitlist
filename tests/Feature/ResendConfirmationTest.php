<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

beforeEach(function () {
    $this->entry = WaitlistEntry::factory()->pending()->onList('beta')->create(['email' => 'user@example.com']);
    $this->travel(10)->minutes();
});

it('rotates the confirm token and re-fires the event', function () {
    $oldHash = $this->entry->currentSubscription->confirm_token_hash;

    $event = null;
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $e) use (&$event) {
        $event = $e;
    });

    $entry = Waitlist::for('beta')->resendConfirmation('user@example.com');

    expect($entry)->not->toBeNull()
        ->and($event->isNewCycle)->toBeFalse()
        ->and($event->requiresConfirmation)->toBeTrue()
        ->and($event->unsubscribeToken)->toBe($this->entry->plainUnsubscribeToken())
        ->and(WaitlistEntry::hashToken($event->confirmToken))->not->toBe($oldHash)
        ->and($entry->currentSubscription->confirmation_count)->toBe(2);

    assertWaitlistInvariants();
});

it('invalidates the previous confirm link', function () {
    $tokens = subscribeAndCapture('launch', 'user@example.com');

    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('launch', 'user@example.com');

    expect(Waitlist::findByConfirmToken($tokens['confirm']))->toBeNull();
});

it('does not extend the retention period', function () {
    $startedAt = $this->entry->currentSubscription->started_at;

    $entry = Waitlist::resendConfirmation('beta', 'user@example.com');

    expect($entry->currentSubscription->started_at->equalTo($startedAt))->toBeTrue();
});

it('returns null for unknown addresses, confirmed cycles and the cooldown alike', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'done@example.com']);
    WaitlistEntry::factory()->pending()->onList('beta')->create(['email' => 'fresh@example.com']);

    Event::fake([EntrySubscribed::class]);

    expect(Waitlist::resendConfirmation('beta', 'nobody@example.com'))->toBeNull()
        ->and(Waitlist::resendConfirmation('beta', 'done@example.com'))->toBeNull()
        ->and(Waitlist::resendConfirmation('beta', 'fresh@example.com'))->toBeNull();

    Event::assertNotDispatched(EntrySubscribed::class);
});

it('stops at the per-cycle cap', function () {
    config()->set(ConfigKey::ResendCooldown->value, null);
    config()->set(ConfigKey::MaxConfirmations->value, 3);

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull()
        ->and($this->entry->fresh()->currentSubscription->confirmation_count)->toBe(3);

    assertWaitlistInvariants();
});

it('resends immediately when the cooldown is disabled', function () {
    config()->set(ConfigKey::ResendCooldown->value, null);

    Event::fake([EntrySubscribed::class]);

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull();

    Event::assertDispatchedTimes(EntrySubscribed::class, 1);
});

it('sends one more past the cap once the last link has expired, so nobody locks the address out', function () {
    config()->set(ConfigKey::ResendCooldown->value, null);
    config()->set(ConfigKey::MaxConfirmations->value, 2);

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull();

    $this->travel(8)->days();

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull()
        ->and($this->entry->fresh()->currentSubscription->hasExpiredToken())->toBeFalse();
});
