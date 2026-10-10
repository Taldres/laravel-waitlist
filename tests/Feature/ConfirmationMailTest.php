<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;

it('records the confirmation mail your listener sent, as proof of the double opt-in', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    Waitlist::confirmationMailed($subscription, 'confirm-mail@2026-10');

    expect(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::ConfirmationRequested, ActivityType::ConfirmationMailed])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationMailed)->sole())
        ->reference->toBe('confirm-mail@2026-10')
        ->waitlist_subscription_id->toBe($subscription->id)
        ->and(Waitlist::personalData('user@example.com')->sole()->toArray()['activity'][2])
        ->toMatchArray(['type' => 'confirmation_mailed', 'reference' => 'confirm-mail@2026-10']);
});

it('clears the reference on erasure, since a message id could lead back to the person', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirmationMailed($tokens['entry']->latestSubscription, '<abc123@mail.example.com>');

    Waitlist::forget('user@example.com');

    expect(WaitlistActivity::query()->where('type', ActivityType::ConfirmationMailed)->sole()->reference)->toBeNull();
});

it('keeps the reference encrypted and takes long ones', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $reference = '<'.str_repeat('a', 300).'@mail.example.com>';

    Waitlist::confirmationMailed($tokens['entry']->latestSubscription, $reference);

    expect(DB::table('waitlist_activity')->whereNotNull('reference')->value('reference'))->not->toBe($reference)
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationMailed)->sole()->reference)->toBe($reference);
});

it('does nothing once the entry is gone, so a retried listener does not fail after mailing', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;
    Waitlist::forget('user@example.com');

    Waitlist::confirmationMailed($subscription, 'confirm-mail@2026-10');

    expect(WaitlistActivity::query()->where('type', ActivityType::ConfirmationMailed)->count())->toBe(0);
});

it('takes back the cooldown and the count of a request whose mail could not be sent', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull();

    expect(Waitlist::confirmationFailed($subscription, 'http-429'))->toBeTrue();

    $subscription->refresh();

    expect($subscription->confirmation_count)->toBe(0)
        ->and($subscription->confirmation_sent_at)->toBeNull()
        ->and(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::ConfirmationRequested, ActivityType::ConfirmationFailed])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationFailed)->sole()->reference)->toBe('http-429');

    $event = null;
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $e) use (&$event) {
        $event = $e;
    });

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and($event->isNewCycle)->toBeTrue()
        ->and($subscription->refresh()->confirmation_count)->toBe(1);

    assertWaitlistInvariants();
});

it('counts only the requests that went out against the cap', function () {
    config()->set(ConfigKey::MaxConfirmations->value, 2);
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    Waitlist::confirmationFailed($subscription);
    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('beta', 'user@example.com');
    $this->travel(10)->minutes();

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and($subscription->refresh()->confirmation_count)->toBe(2);
});

it('leaves a newer request alone, which holds its own cooldown', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $first = $tokens['entry']->latestSubscription;
    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('beta', 'user@example.com');

    expect(Waitlist::confirmationFailed($first))->toBeFalse()
        ->and($first->refresh()->confirmation_count)->toBe(2)
        ->and($first->confirmation_sent_at)->not->toBeNull()
        ->and(activityTypes())->not->toContain(ActivityType::ConfirmationFailed);
});

it('does nothing for a cycle that was confirmed or has ended', function () {
    $confirmed = subscribeAndCapture('beta', 'done@example.com');
    Waitlist::confirm($confirmed['confirm']);
    $left = subscribeAndCapture('launch', 'gone@example.com');
    Waitlist::unsubscribe($left['unsubscribe']);

    expect(Waitlist::confirmationFailed($confirmed['entry']->latestSubscription))->toBeFalse()
        ->and(Waitlist::confirmationFailed($left['entry']->latestSubscription))->toBeFalse()
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationFailed)->count())->toBe(0);
});

it('does nothing once the entry is gone, so a failed listener does not fail again', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;
    Waitlist::forget('user@example.com');

    expect(Waitlist::confirmationFailed($subscription))->toBeFalse();
});

it('does nothing for a cycle that started without a confirmation request', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    expect(Waitlist::confirmationFailed($tokens['entry']->latestSubscription))->toBeFalse();
});
