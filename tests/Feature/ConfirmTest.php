<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

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

it('refuses a link that a resend rotated between its lookup and the confirmation', function () {
    config()->set(ConfigKey::ResendCooldown->value, null);

    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $newest = null;
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$newest) {
        $newest = $event->confirmToken;
    });

    $interleaved = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$interleaved) {
        if (! $interleaved && preg_match('/^update\s+\W?\w*waitlist_subscriptions\W?\s+set\s+\W?confirmed_at\W?\s*=/i', $query) === 1) {
            $interleaved = true;
            Waitlist::resendConfirmation('beta', 'user@example.com');
        }
    });

    expect(fn () => Waitlist::confirm($tokens['confirm']))->toThrow(InvalidTokenException::class)
        ->and($interleaved)->toBeTrue()
        ->and($newest)->not->toBeNull()->not->toBe($tokens['confirm'])
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);

    expect(Waitlist::confirm($newest)->status)->toBe(EntryStatus::Confirmed);

    assertWaitlistInvariants();
});

it('does not confirm with a link that expired after it was looked up', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $subscription = Waitlist::findByConfirmToken($tokens['confirm']);

    $this->travel(8)->days();

    expect(app(SubscriptionLifecycle::class)->confirm($subscription, RequestContext::none()))->toBeFalse()
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);

    assertWaitlistInvariants();
});

it('reports a link that expired between its lookup and the confirmation as expired', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $interleaved = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$interleaved) {
        if (! $interleaved && preg_match('/^update\s+\W?\w*waitlist_subscriptions\W?\s+set\s+\W?confirmed_at\W?\s*=/i', $query) === 1) {
            $interleaved = true;
            WaitlistSubscription::query()->update(['confirm_token_expires_at' => now()->subMinute()]);
        }
    });

    expect(fn () => Waitlist::confirm($tokens['confirm']))->toThrow(ExpiredTokenException::class)
        ->and($interleaved)->toBeTrue()
        ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);
});
