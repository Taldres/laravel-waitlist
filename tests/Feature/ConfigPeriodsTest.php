<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\RequestContext;

/**
 * Every period the config takes, with the accessor that reads it and the
 * way it reaches from now: back for what is kept or throttled, ahead for what
 * is stored as an end.
 *
 * @return array<string, array{0: ConfigKey, 1: string, 2: string}>
 */
function configPeriods(): array
{
    return [
        'the confirm link lifetime' => [ConfigKey::ConfirmTokenTtl, 'confirmTokenTtl', 'minutes ahead'],
        'the manage link lifetime' => [ConfigKey::ManageTokenTtl, 'manageTokenTtl', 'minutes ahead'],
        'the resend cooldown' => [ConfigKey::ResendCooldown, 'resendCooldown', 'minutes back'],
        'the manage request cooldown' => [ConfigKey::ManageRequestCooldown, 'manageRequestCooldown', 'minutes back'],
        'the pending retention' => [ConfigKey::RetentionPendingDays, 'pendingDays', 'days back'],
        'the unsubscribed retention' => [ConfigKey::RetentionUnsubscribedDays, 'unsubscribedDays', 'days back'],
        'the request metadata retention' => [ConfigKey::RetentionRequestMetadataDays, 'requestMetadataDays', 'days back'],
    ];
}

function configPeriodsLongest(string $reach): int
{
    return match ($reach) {
        'days back' => TimestampRange::daysBack(),
        'minutes back' => TimestampRange::minutesBack(),
        'minutes ahead' => TimestampRange::minutesAhead(),
    };
}

describe('the range of a timestamp column', function () {
    it('ends at the last second MySQL and MariaDB hold', function () {
        expect(Carbon::createFromTimestampUTC(TimestampRange::LAST_SECOND)->toDateTimeString())->toBe('2038-01-19 03:14:07');
    });

    it('counts a lifetime up to that second and not past it', function () {
        $this->freezeTime();

        $minutes = TimestampRange::minutesAhead();

        expect(now()->addMinutes($minutes)->getTimestamp())->toBeLessThanOrEqual(TimestampRange::LAST_SECOND)
            ->and(now()->addMinutes($minutes + 1)->getTimestamp())->toBeGreaterThan(TimestampRange::LAST_SECOND);
    });

    it('counts a lookback down to 1970 and not before', function () {
        $this->freezeTime();

        expect(now()->subMinutes(TimestampRange::minutesBack())->getTimestamp())->toBeGreaterThanOrEqual(0)
            ->and(now()->subMinutes(TimestampRange::minutesBack() + 1)->getTimestamp())->toBeLessThan(0)
            ->and(now()->subDays(TimestampRange::daysBack())->getTimestamp())->toBeGreaterThanOrEqual(0)
            ->and(now()->subDays(TimestampRange::daysBack() + 1)->getTimestamp())->toBeLessThan(0);
    });

    it('has nothing left to count once the range is over', function () {
        $this->travelTo(Carbon::createFromTimestampUTC(TimestampRange::LAST_SECOND + 100));

        expect(TimestampRange::minutesAhead())->toBe(0);
    });

    it('gives a cutoff in the past or now, and refuses a period that would move it anywhere else', function (int $days) {
        TimestampRange::daysAgo($days);
    })->with([-1, PHP_INT_MAX, 99_999_999])->throws(InvalidArgumentException::class, 'cannot be counted back from now');

    it('gives the cutoff of a period that fits', function () {
        $this->freezeTime();

        expect(TimestampRange::daysAgo(0)->equalTo(now()))->toBeTrue()
            ->and(TimestampRange::daysAgo(30)->equalTo(now()->subDays(30)))->toBeTrue()
            ->and(TimestampRange::daysAgo(TimestampRange::daysBack())->getTimestamp())->toBeGreaterThanOrEqual(0);
    });
});

describe('a period so large that it would wrap around', function () {
    it('is refused with the key, whether it is a number or the text of one', function (ConfigKey $key, string $accessor, string $reach, mixed $value) {
        $this->freezeTime();
        config()->set($key->value, $value);

        expect(fn () => WaitlistConfig::{$accessor}())->toThrow(InvalidConfigurationException::class, "The {$key->value} config must be at most ".configPeriodsLongest($reach));
    })->with(configPeriods())->with([
        'the largest int' => PHP_INT_MAX,
        'the largest int as text' => (string) PHP_INT_MAX,
    ]);

    it('says why, and in which unit', function () {
        config()->set(ConfigKey::RetentionPendingDays->value, PHP_INT_MAX);

        expect(fn () => WaitlistConfig::pendingDays())->toThrow(InvalidConfigurationException::class, ' days, as a longer one would reach back before 1970');

        config()->set(ConfigKey::ConfirmTokenTtl->value, PHP_INT_MAX);

        expect(fn () => WaitlistConfig::confirmTokenTtl())->toThrow(InvalidConfigurationException::class, ' minutes, as a longer one would end after 2038-01-19 03:14:07 UTC');
    });

    it('is refused by the full check too', function (ConfigKey $key) {
        config()->set($key->value, PHP_INT_MAX);

        expect(fn () => WaitlistConfig::check())->toThrow(InvalidConfigurationException::class, $key->value);
    })->with(array_column(configPeriods(), 0));

    it('is read up to the longest that fits, and refused beyond', function (ConfigKey $key, string $accessor, string $reach) {
        $this->freezeTime();
        $longest = configPeriodsLongest($reach);

        config()->set($key->value, $longest);

        expect(WaitlistConfig::{$accessor}())->toBe($longest);

        config()->set($key->value, $longest + 1);

        expect(fn () => WaitlistConfig::{$accessor}())->toThrow(InvalidConfigurationException::class, $key->value);
    })->with(configPeriods());

    it('keeps a stored end on the right side of now, and inside the column', function () {
        $this->freezeTime();

        config()->set(ConfigKey::ConfirmTokenTtl->value, TimestampRange::minutesAhead());
        config()->set(ConfigKey::ManageTokenTtl->value, TimestampRange::minutesAhead());

        foreach ([WaitlistConfig::confirmTokenExpiresAt(now()), WaitlistConfig::manageTokenExpiresAt(now())] as $expiresAt) {
            expect($expiresAt->isFuture())->toBeTrue()
                ->and($expiresAt->getTimestamp())->toBeLessThanOrEqual(TimestampRange::LAST_SECOND);
        }
    });

    it('keeps a cutoff in the past, and inside the column', function () {
        $this->freezeTime();

        foreach (['pendingDays' => ConfigKey::RetentionPendingDays, 'unsubscribedDays' => ConfigKey::RetentionUnsubscribedDays, 'requestMetadataDays' => ConfigKey::RetentionRequestMetadataDays] as $accessor => $key) {
            config()->set($key->value, TimestampRange::daysBack());

            $cutoff = TimestampRange::daysAgo((int) WaitlistConfig::{$accessor}());

            expect($cutoff->isPast())->toBeTrue("{$key->value} puts the cutoff in the future")
                ->and($cutoff->getTimestamp())->toBeGreaterThanOrEqual(0);
        }
    });

    it('is checked against the clock of the day, as it moves towards 2038', function () {
        $this->travelTo(Carbon::parse('2037-01-01 00:00:00', 'UTC'));
        $untilTheLastSecond = TimestampRange::minutesAhead();
        config()->set(ConfigKey::ConfirmTokenTtl->value, $untilTheLastSecond);
        config()->set(ConfigKey::ManageTokenTtl->value, $untilTheLastSecond);

        expect(WaitlistConfig::confirmTokenExpiresAt(now())->getTimestamp())->toBeLessThanOrEqual(TimestampRange::LAST_SECOND)
            ->and(WaitlistConfig::manageTokenExpiresAt(now())->getTimestamp())->toBeLessThanOrEqual(TimestampRange::LAST_SECOND);

        $this->travel(1)->days();

        expect(fn () => WaitlistConfig::confirmTokenExpiresAt(now()))->toThrow(InvalidConfigurationException::class, ConfigKey::ConfirmTokenTtl->value)
            ->and(fn () => WaitlistConfig::manageTokenExpiresAt(now()))->toThrow(InvalidConfigurationException::class, ConfigKey::ManageTokenTtl->value);
    });

    it('leaves null as the way to never expire, and to switch a period off', function () {
        config()->set(ConfigKey::ConfirmTokenTtl->value, null);
        config()->set(ConfigKey::ResendCooldown->value, null);
        config()->set(ConfigKey::ManageRequestCooldown->value, null);
        config()->set(ConfigKey::RetentionPendingDays->value, null);

        $tokens = subscribeAndCapture('beta', 'user@example.com');

        expect(WaitlistConfig::confirmTokenExpiresAt(now()))->toBeNull()
            ->and($tokens['entry']->currentSubscription->confirm_token_expires_at)->toBeNull()
            ->and(WaitlistConfig::resendCooldown())->toBeNull()
            ->and(WaitlistConfig::manageRequestCooldown())->toBeNull()
            ->and(WaitlistConfig::pendingDays())->toBeNull();
    });

    it('never makes a confirm link that is expired already', function (mixed $value) {
        config()->set(ConfigKey::ConfirmTokenTtl->value, $value);

        expect(fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()))->toThrow(InvalidConfigurationException::class, ConfigKey::ConfirmTokenTtl->value)
            ->and(WaitlistEntry::query()->count())->toBe(0);
    })->with([PHP_INT_MAX, (string) PHP_INT_MAX]);

    it('never sends a new confirm link with an end that wraps around', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com');
        $this->travel(10)->minutes();
        config()->set(ConfigKey::ConfirmTokenTtl->value, PHP_INT_MAX);

        expect(fn () => Waitlist::resendConfirmation('beta', 'user@example.com'))->toThrow(InvalidConfigurationException::class, ConfigKey::ConfirmTokenTtl->value)
            ->and($tokens['entry']->currentSubscription()->sole()->confirmation_count)->toBe(1);
    });

    it('never issues a manage link that is expired already', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com');
        config()->set(ConfigKey::ManageTokenTtl->value, PHP_INT_MAX);

        expect(fn () => Waitlist::manageLink($tokens['entry']))->toThrow(InvalidConfigurationException::class, ConfigKey::ManageTokenTtl->value)
            ->and($tokens['entry']->fresh()->manage_token_hash)->toBeNull();
    });

    it('never removes the throttle on manage link mails', function () {
        Event::fake([ManageLinkRequested::class]);
        $tokens = subscribeAndCapture('beta', 'user@example.com');
        Waitlist::confirm($tokens['confirm']);
        config()->set(ConfigKey::ManageRequestCooldown->value, PHP_INT_MAX);

        expect(fn () => Waitlist::requestManageLink($tokens['unsubscribe']))->toThrow(InvalidConfigurationException::class, ConfigKey::ManageRequestCooldown->value);

        Event::assertNotDispatched(ManageLinkRequested::class);
    });

    it('never erases a signup that is a moment old', function (mixed $value) {
        subscribeAndCapture('beta', 'user@example.com');
        config()->set(ConfigKey::RetentionPendingDays->value, $value);

        expect(fn () => app(PruneEntries::class)())->toThrow(InvalidConfigurationException::class, ConfigKey::RetentionPendingDays->value)
            ->and(WaitlistEntry::query()->count())->toBe(1);
    })->with([PHP_INT_MAX, (string) PHP_INT_MAX]);

    it('never erases someone who left a moment ago', function (mixed $value) {
        $tokens = subscribeAndCapture('beta', 'user@example.com');
        Waitlist::unsubscribe($tokens['unsubscribe']);
        config()->set(ConfigKey::RetentionUnsubscribedDays->value, $value);

        expect(fn () => app(PruneEntries::class)())->toThrow(InvalidConfigurationException::class, ConfigKey::RetentionUnsubscribedDays->value)
            ->and(WaitlistEntry::query()->count())->toBe(1);
    })->with([PHP_INT_MAX, (string) PHP_INT_MAX]);

    it('never clears the request metadata of a moment ago', function (mixed $value) {
        config()->set(ConfigKey::StoreIp->value, true);
        Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), context: new RequestContext('203.0.113.7'));
        config()->set(ConfigKey::RetentionRequestMetadataDays->value, $value);
        $stored = WaitlistActivity::query()->whereNotNull('ip')->count();

        expect(fn () => app(PruneEntries::class)())->toThrow(InvalidConfigurationException::class, ConfigKey::RetentionRequestMetadataDays->value)
            ->and($stored)->toBeGreaterThan(0)
            ->and(WaitlistActivity::query()->whereNotNull('ip')->count())->toBe($stored);
    })->with([PHP_INT_MAX, (string) PHP_INT_MAX]);

    it('is refused by the scopes too, where a period comes from code rather than the config', function () {
        subscribeAndCapture('beta', 'user@example.com');

        expect(fn () => WaitlistEntry::query()->leftBefore(PHP_INT_MAX)->get())->toThrow(InvalidArgumentException::class)
            ->and(fn () => WaitlistEntry::query()->awaitingConfirmationSince(PHP_INT_MAX)->get())->toThrow(InvalidArgumentException::class)
            ->and(fn () => WaitlistEntry::query()->awaitingConfirmationSince(-1)->get())->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(PruneEntries::class)->clearRequestMetadata(PHP_INT_MAX))->toThrow(InvalidArgumentException::class);
    });
});

describe('a setting of the confirmation', function () {
    it('is not read by a signup that sends no confirmation', function () {
        config()->set(ConfigKey::DoubleOptIn->value, false);

        foreach ([ConfigKey::ConfirmTokenTtl, ConfigKey::ResendCooldown, ConfigKey::MaxConfirmations, ConfigKey::MaxPendingPerAddress] as $key) {
            config()->set($key->value, 'soon');
        }

        $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

        expect($result->entry->status)->toBe(EntryStatus::Confirmed);
    });

    it('is not read by a signup that sends one, unless the signup needs it', function (ConfigKey $key) {
        config()->set($key->value, 'soon');

        $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

        expect($result->entry->status)->toBe(EntryStatus::Pending);
    })->with([ConfigKey::ResendCooldown, ConfigKey::MaxConfirmations]);

    it('stops the signups that need it, and names the key', function (ConfigKey $key) {
        config()->set($key->value, 'soon');

        expect(fn () => Waitlist::subscribe('beta', 'user@example.com', waitlistConsent()))->toThrow(InvalidConfigurationException::class, $key->value);
    })->with([ConfigKey::ConfirmTokenTtl, ConfigKey::MaxPendingPerAddress]);

    it('is not read to confirm, unsubscribe or leave', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com');

        foreach ([ConfigKey::ConfirmTokenTtl, ConfigKey::ResendCooldown, ConfigKey::MaxConfirmations, ConfigKey::MaxPendingPerAddress] as $key) {
            config()->set($key->value, 'soon');
        }

        expect(Waitlist::confirm($tokens['confirm'])->status)->toBe(EntryStatus::Confirmed)
            ->and(Waitlist::unsubscribe($tokens['unsubscribe'])->status)->toBe(EntryStatus::Unsubscribed);
    });
});

describe('a setting of the preference page', function () {
    it('is not read to issue a link for a person who is known, which needs no request cooldown', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com');
        Waitlist::confirm($tokens['confirm']);

        config()->set(ConfigKey::ManageRequestCooldown->value, -1);

        $link = Waitlist::manageLink($tokens['entry']);

        expect(Waitlist::findByManageToken($link->token))->not->toBeNull();
    });

    it('is read before a request is claimed, so a link that cannot be issued starts no cooldown', function () {
        Event::fake([ManageLinkRequested::class]);
        $tokens = subscribeAndCapture('beta', 'user@example.com');
        Waitlist::confirm($tokens['confirm']);

        config()->set(ConfigKey::ManageTokenTtl->value, 'soon');

        expect(fn () => Waitlist::requestManageLink($tokens['unsubscribe']))->toThrow(InvalidConfigurationException::class, ConfigKey::ManageTokenTtl->value)
            ->and($tokens['entry']->fresh()->manage_link_sent_at)->toBeNull();

        config()->set(ConfigKey::ManageTokenTtl->value, 60);

        expect(Waitlist::requestManageLink($tokens['unsubscribe']))->toBeTrue();
    });
});
