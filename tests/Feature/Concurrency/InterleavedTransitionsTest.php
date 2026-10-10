<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Actions\ResendConfirmation;
use Taldres\Waitlist\Actions\UnsubscribeEntry;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\ConfirmationOutcome;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

beforeEach(fn () => $this->skipWithoutSecondConnection());

it('settles on unsubscribed when a confirm and an unsubscribe cross', function (string $first) {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => Waitlist::findByConfirmToken($tokens['confirm']));
    $onB = $this->as('b', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe']));

    Event::fake([EntryConfirmed::class, EntryUnsubscribed::class]);

    $confirm = fn () => $this->as('a', fn () => app(SubscriptionLifecycle::class)->confirm($onA, RequestContext::none()));
    $unsubscribe = fn () => $this->as('b', fn () => app(UnsubscribeEntry::class)->unsubscribe($onB));

    if ($first === 'confirm') {
        $confirm();
        $unsubscribe();
    } else {
        $unsubscribe();
        $confirm();
    }

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Unsubscribed);

    Event::assertDispatchedTimes(EntryUnsubscribed::class, 1);
    assertWaitlistInvariants();
})->with(['confirm first' => 'confirm', 'unsubscribe first' => 'unsubscribe']);

it('confirms once when two clients hold the same pending cycle', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => Waitlist::findByConfirmToken($tokens['confirm']));
    $onB = $this->as('b', fn () => Waitlist::findByConfirmToken($tokens['confirm']));

    Event::fake([EntryConfirmed::class]);

    $wonA = $this->as('a', fn () => app(SubscriptionLifecycle::class)->confirm($onA, RequestContext::none()));
    $wonB = $this->as('b', fn () => app(SubscriptionLifecycle::class)->confirm($onB, RequestContext::none()));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Confirmed)
        ->and(WaitlistActivity::query()->where('type', ActivityType::Confirmed)->count())->toBe(1);

    Event::assertDispatchedTimes(EntryConfirmed::class, 1);
    assertWaitlistInvariants();
});

it('unsubscribes once when two clients hold the same open cycle', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe']));
    $onB = $this->as('b', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe']));

    Event::fake([EntryUnsubscribed::class]);

    $this->as('a', fn () => app(UnsubscribeEntry::class)->unsubscribe($onA));
    $this->as('b', fn () => app(UnsubscribeEntry::class)->unsubscribe($onB));

    expect(WaitlistActivity::query()->where('type', ActivityType::Unsubscribed)->count())->toBe(1);

    Event::assertDispatchedTimes(EntryUnsubscribed::class, 1);
    assertWaitlistInvariants();
});

it('collapses two resends that read the same counter', function () {
    config()->set(ConfigKey::ResendCooldown->value, null);

    subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => Waitlist::for('beta')->find('user@example.com')->currentSubscription);
    $onB = $this->as('b', fn () => Waitlist::for('beta')->find('user@example.com')->currentSubscription);

    Event::fake([EntrySubscribed::class]);

    $wonA = $this->as('a', fn () => app(ResendConfirmation::class)->resend($onA));
    $wonB = $this->as('b', fn () => app(ResendConfirmation::class)->resend($onB));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistEntry::query()->firstOrFail()->currentSubscription->confirmation_count)->toBe(2);

    Event::assertDispatchedTimes(EntrySubscribed::class, 1);
    assertWaitlistInvariants();
});

it('creates one entry when the same new address arrives twice', function () {
    Event::fake([EntrySubscribed::class]);

    $this->as('a', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()));
    $this->as('b', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()));

    expect(WaitlistEntry::query()->count())->toBe(1)
        ->and(WaitlistEntry::query()->firstOrFail()->subscriptions()->count())->toBe(1);

    Event::assertDispatchedTimes(EntrySubscribed::class, 1);
    assertWaitlistInvariants();
});

it('opens one new cycle when two re-subscribes cross', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::unsubscribe($tokens['unsubscribe']);

    Event::fake([EntrySubscribed::class]);

    $this->as('a', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()));
    $this->as('b', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()));

    $entry = WaitlistEntry::query()->firstOrFail();

    expect($entry->subscriptions()->count())->toBe(2)
        ->and($entry->status)->toBe(EntryStatus::Pending);

    Event::assertDispatchedTimes(EntrySubscribed::class, 1);
    assertWaitlistInvariants();
});

it('keeps a stale actor from confirming a cycle that was already replaced', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $stale = $this->as('a', fn () => Waitlist::findByConfirmToken($tokens['confirm']));

    Waitlist::unsubscribe($tokens['unsubscribe']);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    Event::fake([EntryConfirmed::class]);

    $won = $this->as('a', fn () => app(SubscriptionLifecycle::class)->confirm($stale, RequestContext::none()));

    expect($won)->toBeFalse()
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);

    Event::assertNotDispatched(EntryConfirmed::class);
    assertWaitlistInvariants();
});

it('never grants after an unsubscribe that crossed it', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    $onA = $this->as('a', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe'])->currentSubscription);
    $onB = $this->as('b', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe']));

    Event::fake([ConsentGranted::class, EntryUnsubscribed::class]);

    $this->as('b', fn () => app(UnsubscribeEntry::class)->unsubscribe($onB));

    $wording = app(PurposeRegistry::class)->wording(app(PurposeRegistry::class)->policy('default', 'beta'), 'newsletter', '2026-10');
    $granted = $this->as('a', fn () => app(SubscriptionLifecycle::class)->grant($onA, $wording, RequestContext::none()));

    expect($granted)->toBeFalse()
        ->and(WaitlistConsent::query()->where('purpose', 'newsletter')->exists())->toBeFalse();

    Event::assertNotDispatched(ConsentGranted::class);
    assertWaitlistInvariants();
});

it('grants once when two clients add the same purpose', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    $onA = $this->as('a', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe'])->currentSubscription);
    $onB = $this->as('b', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe'])->currentSubscription);
    $wording = app(PurposeRegistry::class)->wording(app(PurposeRegistry::class)->policy('default', 'beta'), 'newsletter', '2026-10');

    Event::fake([ConsentGranted::class]);

    $wonA = $this->as('a', fn () => app(SubscriptionLifecycle::class)->grant($onA, $wording, RequestContext::none()));
    $wonB = $this->as('b', fn () => app(SubscriptionLifecycle::class)->grant($onB, $wording, RequestContext::none()));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConsentGranted)->count())->toBe(1);

    Event::assertDispatchedTimes(ConsentGranted::class, 1);
    assertWaitlistInvariants();
});

it('withdraws once when two clients hold the same grant', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($tokens['confirm']);

    $find = fn () => WaitlistConsent::query()->where('purpose', 'newsletter')->firstOrFail();
    $onA = $this->as('a', $find);
    $onB = $this->as('b', $find);

    Event::fake([ConsentWithdrawn::class]);

    $wonA = $this->as('a', fn () => app(SubscriptionLifecycle::class)->withdraw($onA, RequestContext::none()));
    $wonB = $this->as('b', fn () => app(SubscriptionLifecycle::class)->withdraw($onB, RequestContext::none()));

    expect([$wonA, $wonB])->toBe([true, false]);

    Event::assertDispatchedTimes(ConsentWithdrawn::class, 1);
    assertWaitlistInvariants();
});

it('erases once when two clients erase the same entry', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe']));
    $onB = $this->as('b', fn () => Waitlist::findByUnsubscribeToken($tokens['unsubscribe']));

    Event::fake([EntryForgotten::class]);

    $wonA = $this->as('a', fn () => app(EraseEntry::class)($onA));
    $wonB = $this->as('b', fn () => app(EraseEntry::class)($onB));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistActivity::query()->where('type', ActivityType::Erased)->count())->toBe(1);

    Event::assertDispatchedTimes(EntryForgotten::class, 1);
});

it('keeps a confirmation that crossed an expiry', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => Waitlist::findByConfirmToken($tokens['confirm']));
    $this->as('b', fn () => Waitlist::confirm($tokens['confirm']));

    $expired = $this->as('a', fn () => app(SubscriptionLifecycle::class)->end($onA, EndReason::Expired, RequestContext::none()));

    expect($expired)->toBeFalse()
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Confirmed);

    assertWaitlistInvariants();
});

it('counts one of two crossing failure reports of a request, whichever client sends it first', function () {
    $requests = captureRequests();
    subscribeAndCapture('beta', 'user@example.com');
    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('beta', 'user@example.com');

    $onA = $this->as('a', fn () => (new WaitlistSubscription)->newFromBuilder($requests[1]->getAttributes()));
    $onB = $this->as('b', fn () => (new WaitlistSubscription)->newFromBuilder($requests[1]->getAttributes()));

    $wonA = $this->as('a', fn () => Waitlist::confirmationFailed($onA, 'from a'));
    $wonB = $this->as('b', fn () => Waitlist::confirmationFailed($onB, 'from b'));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistSubscription::query()->sole()->confirmation_count)->toBe(1)
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationFailed)->count())->toBe(1);

    assertWaitlistInvariants();
});

it('settles a mail report and a failure report that cross on the first of them', function (string $first) {
    $requests = captureRequests();
    subscribeAndCapture('beta', 'user@example.com');

    $onA = $this->as('a', fn () => (new WaitlistSubscription)->newFromBuilder($requests[0]->getAttributes()));
    $onB = $this->as('b', fn () => (new WaitlistSubscription)->newFromBuilder($requests[0]->getAttributes()));

    $mailed = fn () => $this->as('a', fn () => Waitlist::confirmationMailed($onA, 'mail-1'));
    $failed = fn () => $this->as('b', fn () => Waitlist::confirmationFailed($onB, 'http-500'));

    [$result, $other] = $first === 'mailed' ? [$mailed(), $failed()] : [$failed(), $mailed()];

    // The failure first leaves room for the mail after all; the mail first closes the request.
    expect($result)->toBeTrue()
        ->and($other)->toBe($first === 'failed');

    $subscription = WaitlistSubscription::query()->sole();

    expect($subscription->confirmation_count)->toBe(1)
        ->and($subscription->confirmation_outcome)->toBe(ConfirmationOutcome::Mailed);

    assertWaitlistInvariants();
})->with(['mailed first' => 'mailed', 'failed first' => 'failed']);
