<?php

declare(strict_types=1);

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
