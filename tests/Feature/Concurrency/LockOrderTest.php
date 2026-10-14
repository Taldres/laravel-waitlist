<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

/*
 * One process cannot deadlock itself: the first lock wait blocks it. So the
 * second client gives up after a second, and the statement it gave up on
 * shows where it waited; a timeout that is retried takes a few seconds. Every
 * step must wait at the entry row, the lock an erasure takes first, never
 * behind it.
 */

beforeEach(function () {
    $this->skipWithoutSecondConnection();

    $a = DB::connection('waitlist_a');
    $a->statement($a->getDriverName() === 'pgsql' ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
});

const ENTRY_LOCK = '/SQL: select .*waitlist_entries.* for update/i';

it('makes an erasure wait at the entry while a confirm holds it', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $onB = $this->as('b', fn () => Waitlist::findByConfirmToken($tokens['confirm']));
    $onA = $this->as('a', fn () => WaitlistEntry::query()->firstOrFail());

    $waitedAt = null;
    DB::connection('waitlist_b')->beforeExecuting(function (string $query) use (&$waitedAt, $onA) {
        if ($waitedAt === null && preg_match('/^insert into \W?waitlist_activity/i', $query) === 1) {
            $waitedAt = false;

            try {
                $this->as('a', fn () => app(EraseEntry::class)($onA));
            } catch (PDOException $exception) {
                // A deadlock exception wraps the query exception when nested.
                $waitedAt = $exception->getMessage();
            }
        }
    });

    expect($this->as('b', fn () => app(SubscriptionLifecycle::class)->confirm($onB, RequestContext::none())))->toBeTrue()
        ->and($waitedAt)->toMatch(ENTRY_LOCK)
        ->and($this->as('a', fn () => app(EraseEntry::class)($onA)))->toBeTrue();
});

it('makes a second resubscribe wait at the entry instead of crossing the first', function () {
    config()->set(ConfigKey::ResendCooldown->value, null);
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::unsubscribe($tokens['unsubscribe']);

    $waitedAt = null;
    DB::connection('waitlist_b')->beforeExecuting(function (string $query) use (&$waitedAt) {
        if ($waitedAt === null && preg_match('/^update \W?waitlist_entries/i', $query) === 1) {
            $waitedAt = false;

            try {
                $this->as('a', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()));
            } catch (PDOException $exception) {
                // A deadlock exception wraps the query exception when nested.
                $waitedAt = $exception->getMessage();
            }
        }
    });

    expect($this->as('b', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome))->toBe(SubscribeOutcome::Resubscribed)
        ->and($waitedAt)->toMatch(ENTRY_LOCK)
        ->and(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome)->toBe(SubscribeOutcome::ConfirmationResent);

    assertWaitlistInvariants();
});

it('lets signups of different addresses run side by side', function () {
    $other = null;
    DB::connection('waitlist_b')->beforeExecuting(function (string $query) use (&$other) {
        if ($other === null && preg_match('/^insert into \W?waitlist_subscriptions/i', $query) === 1) {
            $other = false;
            $other = $this->as('a', fn () => Waitlist::subscribe('beta', 'other@example.com', waitlistConsent())->outcome);
        }
    });

    expect($this->as('b', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome))->toBe(SubscribeOutcome::Started)
        ->and($other)->toBe(SubscribeOutcome::Started);

    assertWaitlistInvariants();
});

it('starts afresh when the address is erased while its resubscribe is on the way', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::unsubscribe($tokens['unsubscribe']);
    $onA = $this->as('a', fn () => WaitlistEntry::query()->firstOrFail());

    $erased = false;
    DB::connection('waitlist_b')->beforeExecuting(function (string $query) use (&$erased, $onA) {
        if (! $erased && preg_match('/^select .*waitlist_entries.* for update/i', $query) === 1) {
            $erased = true;
            $this->as('a', fn () => app(EraseEntry::class)($onA));
        }
    });

    expect($this->as('b', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome))->toBe(SubscribeOutcome::Started)
        ->and($erased)->toBeTrue()
        ->and(WaitlistEntry::query()->sole()->isNot($onA))->toBeTrue();

    assertWaitlistInvariants();
});

it('mails one manage link when the address asks on two lists at once', function () {
    foreach (['alpha', 'beta'] as $list) {
        Waitlist::confirm(subscribeAndCapture($list, 'user@example.com')['confirm']);
    }

    Event::fake([ManageLinkRequested::class]);

    $other = null;
    // Right after the request for alpha locked the address's entries.
    DB::listen(function (QueryExecuted $query) use (&$other) {
        if ($other === null && $query->connectionName === 'waitlist_b' && preg_match('/^select .*waitlist_entries.* order by .* for update/i', $query->sql) === 1) {
            $other = false;

            try {
                $other = $this->as('a', fn () => Waitlist::for('beta')->requestManageLink('user@example.com'));
            } catch (PDOException $exception) {
                $other = $exception->getMessage();
            }
        }
    });

    expect($this->as('b', fn () => Waitlist::for('alpha')->requestManageLink('user@example.com')))->toBeTrue()
        ->and($other)->toMatch(ENTRY_LOCK);

    Event::assertDispatchedTimes(ManageLinkRequested::class, 1);
    expect(Waitlist::for('beta')->requestManageLink('user@example.com'))->toBeFalse();
});

it('makes an unsubscribe wait at the entry while a grant holds its cycle', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);
    $manage = manageTokenFor($tokens['entry']);

    $waitedAt = null;
    DB::connection('waitlist_b')->beforeExecuting(function (string $query) use (&$waitedAt, $tokens) {
        if ($waitedAt === null && preg_match('/^insert into \W?waitlist_consents/i', $query) === 1) {
            $waitedAt = false;

            try {
                $this->as('a', fn () => Waitlist::unsubscribe($tokens['unsubscribe']));
            } catch (PDOException $exception) {
                $waitedAt = $exception->getMessage();
            }
        }
    });

    $this->as('b', fn () => Waitlist::grantConsent($manage, 'newsletter', '2026-10'));

    expect($waitedAt)->toMatch(ENTRY_LOCK)
        ->and(WaitlistEntry::query()->firstOrFail()->hasConsentFor('newsletter'))->toBeTrue();

    Waitlist::unsubscribe($tokens['unsubscribe']);

    expect(WaitlistEntry::query()->firstOrFail()->hasConsentFor('newsletter'))->toBeFalse();
    assertWaitlistInvariants();
});

it('acts on the entry a concurrent first signup created between lookup and insert', function () {
    $created = null;
    DB::connection('waitlist_a')->beforeExecuting(function (string $query) use (&$created) {
        if ($created === null && preg_match('/^insert into \W?waitlist_entries/i', $query) === 1) {
            $created = false;
            $created = $this->as('b', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome);
        }
    });

    expect($this->as('a', fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->outcome))->toBe(SubscribeOutcome::ResendSuppressed)
        ->and($created)->toBe(SubscribeOutcome::Started)
        ->and(WaitlistEntry::query()->count())->toBe(1);

    assertWaitlistInvariants();
});
