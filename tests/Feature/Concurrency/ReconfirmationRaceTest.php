<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Actions\UnsubscribeEntry;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ReconfirmationRequested;
use Taldres\Waitlist\Events\SubscriptionLapsed;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

beforeEach(function () {
    $this->skipWithoutSecondConnection();

    config()->set(ConfigKey::RetentionReconfirmAfterDays->value, 365);
});

/**
 * A confirmed address a year on, its cycle due to be asked again.
 */
function dueCycle(): WaitlistSubscription
{
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);
    test()->travel(400)->days();

    return WaitlistEntry::query()->sole()->currentSubscription;
}

/**
 * Asks the due cycle and returns the plain token of the request.
 */
function askedCycle(): string
{
    dueCycle();
    $token = null;

    Event::listen(ReconfirmationRequested::class, function (ReconfirmationRequested $event) use (&$token): void {
        $token = $event->confirmToken;
    });

    test()->artisan('waitlist:prune')->assertSuccessful();

    return $token;
}

function ask(WaitlistSubscription $cycle): bool
{
    return app(SubscriptionLifecycle::class)->requestReconfirmation($cycle, Str::random(64), Carbon::now()->subDays(365), Carbon::now()->addDays(30), RequestContext::none());
}

it('asks once when two runs claim the same cycle', function () {
    dueCycle();

    $onA = $this->as('a', fn () => WaitlistEntry::query()->sole()->currentSubscription);
    $onB = $this->as('b', fn () => WaitlistEntry::query()->sole()->currentSubscription);

    Event::fake([ReconfirmationRequested::class]);

    $wonA = $this->as('a', fn () => ask($onA));
    $wonB = $this->as('b', fn () => ask($onB));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistActivity::query()->where('type', ActivityType::ReconfirmationRequested)->count())->toBe(1);

    Event::assertDispatchedTimes(ReconfirmationRequested::class, 1);
    assertWaitlistInvariants();
});

it('answers once when two clients post the same link', function () {
    $token = askedCycle();

    $onA = $this->as('a', fn () => Waitlist::findByConfirmToken($token));
    $onB = $this->as('b', fn () => Waitlist::findByConfirmToken($token));

    $wonA = $this->as('a', fn () => app(SubscriptionLifecycle::class)->reconfirm($onA, RequestContext::none()));
    $wonB = $this->as('b', fn () => app(SubscriptionLifecycle::class)->reconfirm($onB, RequestContext::none()));

    expect([$wonA, $wonB])->toBe([true, false])
        ->and(WaitlistActivity::query()->where('type', ActivityType::Reconfirmed)->count())->toBe(1);

    assertWaitlistInvariants();
});

it('keeps an answer that crossed a lapse', function () {
    $token = askedCycle();

    $onA = $this->as('a', fn () => Waitlist::findByConfirmToken($token));
    $this->as('b', fn () => Waitlist::confirm($token));

    $this->travel(31)->days();
    Event::fake([SubscriptionLapsed::class]);

    expect($this->as('a', fn () => app(SubscriptionLifecycle::class)->end($onA, EndReason::Lapsed, RequestContext::none())))->toBeFalse()
        ->and(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Confirmed);

    Event::assertNotDispatched(SubscriptionLapsed::class);
    assertWaitlistInvariants();
});

it('reports an answer that lost to a lapse as invalid', function () {
    $token = askedCycle();

    $onB = $this->as('b', fn () => Waitlist::findByConfirmToken($token));
    $this->travel(31)->days();
    $this->as('a', fn () => $this->artisan('waitlist:prune')->assertSuccessful());

    expect($this->as('b', fn () => app(SubscriptionLifecycle::class)->reconfirm($onB, RequestContext::none())))->toBeFalse()
        ->and(fn () => $this->as('b', fn () => Waitlist::confirm($token)))->toThrow(InvalidTokenException::class)
        ->and(WaitlistEntry::query()->sole()->latestSubscription->end_reason)->toBe(EndReason::Lapsed);

    assertWaitlistInvariants();
});

it('settles on unsubscribed when an answer and an unsubscribe cross', function (string $first) {
    $token = askedCycle();
    $unsubscribeToken = WaitlistEntry::query()->sole()->plainUnsubscribeToken();

    $onA = $this->as('a', fn () => Waitlist::findByConfirmToken($token));
    $onB = $this->as('b', fn () => Waitlist::findByUnsubscribeToken($unsubscribeToken));

    $answer = fn () => $this->as('a', fn () => app(SubscriptionLifecycle::class)->reconfirm($onA, RequestContext::none()));
    $unsubscribe = fn () => $this->as('b', fn () => app(UnsubscribeEntry::class)->unsubscribe($onB));

    if ($first === 'answer') {
        $answer();
        $unsubscribe();
    } else {
        $unsubscribe();
        expect($answer())->toBeFalse();
    }

    expect(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Unsubscribed)
        ->and(WaitlistEntry::query()->sole()->latestSubscription->end_reason)->toBe(EndReason::Unsubscribed);

    assertWaitlistInvariants();
})->with(['answer first' => 'answer', 'unsubscribe first' => 'unsubscribe']);

it('asks nobody when the entry was erased on the way', function () {
    dueCycle();

    $onA = $this->as('a', fn () => WaitlistEntry::query()->sole()->currentSubscription);
    $this->as('b', fn () => app(EraseEntry::class)(WaitlistEntry::query()->sole()));

    Event::fake([ReconfirmationRequested::class]);

    expect($this->as('a', fn () => ask($onA)))->toBeFalse();

    Event::assertNotDispatched(ReconfirmationRequested::class);
});

it('reports success to an answer that lost to the same answer from another client', function () {
    $token = askedCycle();

    $entry = $this->as('a', fn () => DB::connection('waitlist_a')->transaction(function () use ($token) {
        WaitlistEntry::query()->count();
        $this->as('b', fn () => Waitlist::confirm($token));

        return Waitlist::confirm($token);
    }, attempts: 3));

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and(WaitlistActivity::query()->where('type', ActivityType::Reconfirmed)->count())->toBe(1);

    assertWaitlistInvariants();
});

it('reports an answer read before the deadline but written after it as expired', function () {
    $token = askedCycle();

    // The deadline passes between the lookup and the update: the update locks
    // the entry first, and the other client moves the deadline just before.
    $moved = false;
    DB::connection('waitlist_a')->beforeExecuting(function (string $query) use (&$moved): void {
        if (! $moved && preg_match('/^select .*waitlist_entries.* for update/i', $query) === 1) {
            $moved = true;
            $this->as('b', fn () => WaitlistSubscription::query()->update(['confirm_token_expires_at' => now()->subSecond()]));
        }
    });

    expect(fn () => $this->as('a', fn () => Waitlist::confirm($token)))->toThrow(ExpiredTokenException::class)
        ->and($moved)->toBeTrue()
        ->and(WaitlistEntry::query()->sole()->latestSubscription->awaitsReconfirmation())->toBeTrue();
});
