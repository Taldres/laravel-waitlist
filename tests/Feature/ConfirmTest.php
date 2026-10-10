<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Facades\Waitlist;

it('confirms a pending cycle via its token', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Event::fake([EntryConfirmed::class]);

    $entry = Waitlist::confirm($tokens['confirm']);

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->confirmed_at)->not->toBeNull()
        ->and(activityTypes())->toContain(ActivityType::Confirmed);

    Event::assertDispatchedTimes(EntryConfirmed::class, 1);
    assertWaitlistInvariants();
});

it('carries a working unsubscribe link on the event', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(unsubscribe: 'https://app.test/unsubscribe/{token}'));

    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $confirmed = null;
    Event::listen(EntryConfirmed::class, function (EntryConfirmed $event) use (&$confirmed) {
        $confirmed = $event;
    });

    Waitlist::confirm($tokens['confirm']);

    expect($confirmed->unsubscribeUrl)->toBe("https://app.test/unsubscribe/{$confirmed->unsubscribeToken}")
        ->and($confirmed->unsubscribeToken)->toBe($tokens['unsubscribe'])
        ->and(Waitlist::unsubscribe($confirmed->unsubscribeToken)->status)->toBe(EntryStatus::Unsubscribed);
});

it('is idempotent and fires the event only once', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Event::fake([EntryConfirmed::class]);

    Waitlist::confirm($tokens['confirm']);

    expect(Waitlist::confirm($tokens['confirm'])->status)->toBe(EntryStatus::Confirmed);

    Event::assertDispatchedTimes(EntryConfirmed::class, 1);
    assertWaitlistInvariants();
});

it('rejects unknown tokens', function () {
    Waitlist::confirm('unknown-token');
})->throws(InvalidTokenException::class);

it('rejects expired tokens', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $this->travel(8)->days();

    Waitlist::confirm($tokens['confirm']);
})->throws(ExpiredTokenException::class);

it('refuses a token whose cycle has ended, so a link cannot undo an unsubscribe', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    Waitlist::unsubscribe($tokens['unsubscribe']);

    expect(fn () => Waitlist::confirm($tokens['confirm']))->toThrow(InvalidTokenException::class);

    assertWaitlistInvariants();
});

it('can make the link single-use', function () {
    config()->set(ConfigKey::InvalidateConfirmToken->value, true);

    $tokens = subscribeAndCapture('beta', 'user@example.com');

    expect(Waitlist::confirm($tokens['confirm'])->status)->toBe(EntryStatus::Confirmed)
        ->and(fn () => Waitlist::confirm($tokens['confirm']))->toThrow(InvalidTokenException::class);
});
