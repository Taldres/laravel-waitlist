<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\ConfirmationOutcome;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistSubscription;

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

function mailActivity(ActivityType $type): int
{
    return WaitlistActivity::query()->where('type', $type)->count();
}

it('takes back the cooldown and the count of a request whose mail could not be sent', function () {
    $requests = captureRequests();
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull();

    expect(Waitlist::confirmationFailed($requests[0], 'http-429'))->toBeTrue();

    $subscription->refresh();

    expect($subscription->confirmation_count)->toBe(0)
        ->and($subscription->confirmation_sent_at)->toBeNull()
        ->and($subscription->confirmation_outcome)->toBe(ConfirmationOutcome::Failed)
        ->and(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::ConfirmationRequested, ActivityType::ConfirmationFailed])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationFailed)->sole()->reference)->toBe('http-429');

    $event = null;
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $e) use (&$event) {
        $event = $e;
    });

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and($event->isNewCycle)->toBeTrue()
        ->and($subscription->refresh()->confirmation_count)->toBe(1)
        ->and($subscription->confirmation_outcome)->toBeNull();

    assertWaitlistInvariants();
});

it('counts a failure of a later request once, when the count is above one', function () {
    $requests = captureRequests();
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription->refresh();

    expect($subscription->confirmation_count)->toBe(2)
        ->and(Waitlist::confirmationFailed($requests[1], 'first report'))->toBeTrue()
        ->and($subscription->refresh()->confirmation_count)->toBe(1);

    expect(Waitlist::confirmationFailed($requests[1], 'second report'))->toBeFalse()
        ->and(Waitlist::confirmationFailed($requests[1], 'third report'))->toBeFalse()
        ->and($subscription->refresh()->confirmation_count)->toBe(1)
        ->and(mailActivity(ActivityType::ConfirmationFailed))->toBe(1)
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationFailed)->sole()->reference)->toBe('first report');

    assertWaitlistInvariants();
});

it('changes nothing for a late report about a request that a newer one replaced', function () {
    $requests = captureRequests();
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    Waitlist::confirmationFailed($requests[0], 'first report');
    Waitlist::resendConfirmation('beta', 'user@example.com');

    // The newer request holds a cooldown and a count of one, the same as the earlier one did.
    $newer = $subscription->refresh();
    $sentAt = $newer->confirmation_sent_at;

    expect($newer->confirmation_count)->toBe(1)
        ->and(Waitlist::confirmationFailed($requests[0], 'late duplicate'))->toBeFalse()
        ->and(Waitlist::confirmationMailed($requests[0], 'late mail'))->toBeFalse();

    $newer->refresh();

    expect($newer->confirmation_count)->toBe(1)
        ->and($newer->confirmation_sent_at->equalTo($sentAt))->toBeTrue()
        ->and($newer->confirmation_outcome)->toBeNull()
        ->and(mailActivity(ActivityType::ConfirmationFailed))->toBe(1)
        ->and(mailActivity(ActivityType::ConfirmationMailed))->toBe(0);

    assertWaitlistInvariants();
});

it('records the report that the mail went out once per request', function () {
    $requests = captureRequests();
    subscribeAndCapture('beta', 'user@example.com');

    expect(Waitlist::confirmationMailed($requests[0], 'mail-1'))->toBeTrue()
        ->and(Waitlist::confirmationMailed($requests[0], 'mail-2'))->toBeFalse()
        ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationMailed)->sole()->reference)->toBe('mail-1')
        ->and(WaitlistSubscription::query()->sole()->confirmation_outcome)->toBe(ConfirmationOutcome::Mailed);

    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('beta', 'user@example.com');

    expect(Waitlist::confirmationMailed($requests[1], 'mail-3'))->toBeTrue()
        ->and(mailActivity(ActivityType::ConfirmationMailed))->toBe(2);

    assertWaitlistInvariants();
});

it('ignores a failure report once the mail was reported as sent', function () {
    $requests = captureRequests();
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    Waitlist::confirmationMailed($requests[0], 'mail-1');

    expect(Waitlist::confirmationFailed($requests[0], 'http-500'))->toBeFalse();

    $subscription->refresh();

    expect($subscription->confirmation_count)->toBe(1)
        ->and($subscription->confirmation_sent_at)->not->toBeNull()
        ->and(mailActivity(ActivityType::ConfirmationFailed))->toBe(0);
});

it('accepts the mail of a failed request once, and takes the cooldown and the count back', function () {
    $requests = captureRequests();
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $subscription = $tokens['entry']->latestSubscription;

    Waitlist::confirmationFailed($requests[0], 'http-429');

    expect($subscription->refresh()->confirmation_count)->toBe(0);

    // The failed job is retried and the provider accepts the mail.
    expect(Waitlist::confirmationMailed($requests[0], 'mail-1'))->toBeTrue();

    $subscription->refresh();

    expect($subscription->confirmation_count)->toBe(1)
        ->and($subscription->confirmation_sent_at)->not->toBeNull()
        ->and($subscription->confirmation_outcome)->toBe(ConfirmationOutcome::Mailed)
        ->and(Waitlist::resendConfirmation('beta', 'user@example.com'))->toBeNull()
        ->and(Waitlist::confirmationMailed($requests[0], 'mail-2'))->toBeFalse()
        ->and(Waitlist::confirmationFailed($requests[0], 'again'))->toBeFalse()
        ->and(activityTypes())->toBe([
            ActivityType::Subscribed,
            ActivityType::ConfirmationRequested,
            ActivityType::ConfirmationFailed,
            ActivityType::ConfirmationMailed,
        ]);

    assertWaitlistInvariants();
});

it('counts only the requests that went out against the cap', function () {
    config()->set(ConfigKey::MaxConfirmations->value, 2);
    $requests = captureRequests();
    subscribeAndCapture('beta', 'user@example.com');

    Waitlist::confirmationFailed($requests[0]);
    $this->travel(10)->minutes();
    Waitlist::resendConfirmation('beta', 'user@example.com');
    $this->travel(10)->minutes();

    expect(Waitlist::resendConfirmation('beta', 'user@example.com'))->not->toBeNull()
        ->and(count($requests))->toBe(3);
});

it('reports a request of a cycle that was confirmed, but not a failure', function () {
    $requests = captureRequests();
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    expect(Waitlist::confirmationFailed($requests[0]))->toBeFalse()
        ->and(Waitlist::confirmationMailed($requests[0], 'mail-1'))->toBeTrue();
});

it('has no request to report for a cycle that has ended or never had one', function () {
    $requests = captureRequests();
    $left = subscribeAndCapture('beta', 'gone@example.com');
    Waitlist::unsubscribe($left['unsubscribe']);

    expect(Waitlist::confirmationFailed($requests[0]))->toBeFalse()
        ->and(Waitlist::confirmationMailed($requests[0], 'mail-1'))->toBeFalse();

    config()->set(ConfigKey::DoubleOptIn->value, false);
    $none = subscribeAndCapture('launch', 'none@example.com');

    expect(Waitlist::confirmationFailed($none['entry']->latestSubscription))->toBeFalse()
        ->and(Waitlist::confirmationMailed($none['entry']->latestSubscription, 'mail-1'))->toBeFalse()
        ->and(mailActivity(ActivityType::ConfirmationFailed) + mailActivity(ActivityType::ConfirmationMailed))->toBe(0);
});

it('has nothing to report once the entry is gone, so a failed listener does not fail again', function () {
    $requests = captureRequests();
    subscribeAndCapture('beta', 'user@example.com');
    Waitlist::forget('user@example.com');

    expect(Waitlist::confirmationFailed($requests[0]))->toBeFalse()
        ->and(Waitlist::confirmationMailed($requests[0], 'mail-1'))->toBeFalse();
});
