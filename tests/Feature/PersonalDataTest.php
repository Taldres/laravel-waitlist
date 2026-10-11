<?php

declare(strict_types=1);

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Support\RequestContext;

it('discloses the whole history for an address', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent('2026-09'), 'newsletter' => '2026-10']);
    Waitlist::confirm($tokens['confirm']);
    Waitlist::unsubscribe($tokens['unsubscribe']);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    $data = Waitlist::personalData('user@example.com')->firstOrFail();

    expect($data->list)->toBe('beta')
        ->and($data->email)->toBe('user@example.com')
        ->and($data->status)->toBe(EntryStatus::Pending)
        ->and($data->subscriptions)->toHaveCount(2)
        ->and($data->subscriptions[0]->consents)->toHaveCount(2)
        ->and($data->subscriptions[0]->consents[0]->text)->toBe('Earlier wording.')
        ->and($data->subscriptions[0]->consents[0]->version)->toBe('2026-09')
        ->and($data->subscriptions[0]->consents[1]->purpose)->toBe('newsletter')
        ->and($data->subscriptions[0]->confirmedAt)->not->toBeNull()
        ->and($data->subscriptions[0]->endReason)->toBe(EndReason::Unsubscribed)
        ->and($data->subscriptions[1]->endedAt)->toBeNull()
        ->and($data->subscriptions[1]->consents[0]->text)->toBe('Email me when early access opens.')
        ->and($data->effectivePurposes())->toBe([]);

    expect(array_map(fn ($row) => $row->type, $data->activity))->toBe([
        ActivityType::Subscribed,
        ActivityType::ConfirmationRequested,
        ActivityType::Confirmed,
        ActivityType::Unsubscribed,
        ActivityType::Resubscribed,
        ActivityType::ConfirmationRequested,
    ]);
});

it('discloses request metadata that was stored, and nothing when it was not', function () {
    config()->set(ConfigKey::StoreIp->value, true);

    Waitlist::subscribe('beta', 'stored@example.com', waitlistConsent(), context: new RequestContext('127.0.0.1', 'TestBrowser'));

    config()->set(ConfigKey::StoreIp->value, false);

    Waitlist::subscribe('beta', 'plain@example.com', waitlistConsent(), context: new RequestContext('127.0.0.1', 'TestBrowser'));

    expect(Waitlist::personalData('stored@example.com')->firstOrFail()->activity[0]->ip)->toBe('127.0.0.1')
        ->and(Waitlist::personalData('stored@example.com')->firstOrFail()->activity[0]->userAgent)->toBeNull()
        ->and(Waitlist::personalData('plain@example.com')->firstOrFail()->activity[0]->ip)->toBeNull();
});

it('never exposes tokens', function () {
    subscribeAndCapture('beta', 'user@example.com');

    $json = (string) json_encode(Waitlist::personalData('user@example.com')->firstOrFail());

    expect($json)->not->toContain('token')
        ->and($json)->not->toContain('hash');
});

it('is scopeable to one list and returns one record per entry', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('launch', 'user@example.com', waitlistConsent());

    expect(Waitlist::personalData('user@example.com'))->toHaveCount(2)
        ->and(Waitlist::for('beta')->personalData('user@example.com'))->toHaveCount(1);
});

describe('with a date class the app chose with Date::use()', function () {
    afterEach(fn () => Date::useDefault());

    dataset('date classes', [
        'CarbonImmutable' => [CarbonImmutable::class],
        'Carbon\\Carbon' => [BaseCarbon::class],
    ]);

    it('discloses the history', function (string $dateClass) {
        Date::use($dateClass);

        $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
        Waitlist::confirm($tokens['confirm']);
        Waitlist::for('beta')->withdraw('user@example.com', 'newsletter');
        Waitlist::unsubscribe($tokens['unsubscribe']);

        $data = Waitlist::personalData('user@example.com')->firstOrFail();
        $array = $data->toArray();
        $timestamp = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

        expect($array['created_at'])->toMatch($timestamp)
            ->and($array['subscriptions'][0]['started_at'])->toMatch($timestamp)
            ->and($array['subscriptions'][0]['confirmed_at'])->toMatch($timestamp)
            ->and($array['subscriptions'][0]['ended_at'])->toMatch($timestamp)
            ->and($array['subscriptions'][0]['consents'][0]['granted_at'])->toMatch($timestamp)
            ->and($array['subscriptions'][0]['consents'][1]['withdrawn_at'])->toMatch($timestamp)
            ->and($array['activity'][0]['occurred_at'])->toMatch($timestamp)
            ->and((string) json_encode($data))->not->toContain('token')->not->toContain('hash');

        Date::useDefault();

        expect(Waitlist::personalData('user@example.com')->firstOrFail()->toArray())->toBe($array);
    })->with('date classes');

    it('discloses the history through waitlist:show', function (string $dateClass) {
        Date::use($dateClass);

        $tokens = subscribeAndCapture('beta', 'user@example.com');
        Waitlist::confirm($tokens['confirm']);

        $this->artisan('waitlist:show', ['email' => 'user@example.com', '--json' => true])->assertSuccessful();
        $this->artisan('waitlist:show', ['email' => 'user@example.com', '--pretty' => true])->assertSuccessful();
        $this->artisan('waitlist:show', ['email' => 'user@example.com'])->assertSuccessful();
    })->with('date classes');

    it('discloses the history over the manage link', function (string $dateClass) {
        Date::use($dateClass);

        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);

        require __DIR__.'/../../routes/waitlist.php';

        $tokens = subscribeAndCapture('beta', 'user@example.com');
        Waitlist::confirm($tokens['confirm']);

        $this->postJson('/waitlist/manage/'.manageTokenFor($tokens['entry']).'/data')
            ->assertOk()
            ->assertJsonPath('email', 'user@example.com');
    })->with('date classes');
});
