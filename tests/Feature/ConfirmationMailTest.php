<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Taldres\Waitlist\Enums\ActivityType;
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
