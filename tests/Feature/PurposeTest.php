<?php

declare(strict_types=1);

use Taldres\ImmutableAttributes\Exceptions\ImmutableAttributeException;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;

it('records each purpose with the wording of the version shown', function () {
    $result = Waitlist::subscribe('beta', 'user@example.com', ['waitlist' => '2026-10', 'newsletter' => '2026-10']);

    $consents = $result->subscription->consents;

    expect($consents)->toHaveCount(2)
        ->and($consents[0]->only('purpose', 'version', 'text'))->toBe([
            'purpose' => 'waitlist',
            'version' => '2026-10',
            'text' => 'Email me when early access opens.',
        ])
        ->and($consents[1]->purpose)->toBe('newsletter')
        ->and($consents[0]->granted_at->equalTo($result->subscription->started_at))->toBeTrue();

    assertWaitlistInvariants();
});

it('accepts an older version and stores the wording that was shown then', function () {
    $result = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent('2026-09'));

    expect($result->subscription->consents[0]->text)->toBe('Earlier wording.');
});

it('requires the primary purpose', function (array $purposes) {
    Waitlist::subscribe('beta', 'user@example.com', $purposes);
})->with([
    'nothing' => [[]],
    'only an optional purpose' => [['newsletter' => '2026-10']],
])->throws(MissingConsentException::class);

it('rejects purposes the list does not offer and versions that do not exist', function (array $purposes) {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'));

    Waitlist::subscribe('beta', 'user@example.com', $purposes);
})->with([
    'unknown purpose' => [[...waitlistConsent(), 'marketing' => '2026-10']],
    'configured, but not offered by this list' => [[...waitlistConsent(), 'newsletter' => '2026-10']],
    'unknown version' => [['waitlist' => '2025-01']],
])->throws(UnknownPurposeException::class);

it('stores nothing when the consent is invalid', function () {
    try {
        Waitlist::subscribe('beta', 'user@example.com', ['waitlist' => '2025-01']);
    } catch (UnknownPurposeException) {
    }

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('puts a purpose in force only while the cycle is confirmed and open', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    $entry = $tokens['entry'];

    expect($entry->fresh()->purposes)->toBe([])
        ->and(WaitlistEntry::query()->whereConsentedTo('newsletter')->count())->toBe(0);

    Waitlist::confirm($tokens['confirm']);

    expect($entry->fresh()->purposes)->toBe(['waitlist', 'newsletter'])
        ->and($entry->fresh()->hasConsentFor('newsletter'))->toBeTrue()
        ->and(WaitlistEntry::query()->whereConsentedTo('newsletter')->count())->toBe(1);

    Waitlist::unsubscribe($tokens['unsubscribe']);

    expect($entry->fresh()->purposes)->toBe([])
        ->and($entry->fresh()->hasConsentFor('waitlist'))->toBeFalse()
        ->and(WaitlistEntry::query()->whereConsentedTo('waitlist')->count())->toBe(0)
        ->and(WaitlistConsent::query()->whereNotNull('withdrawn_at')->count())->toBe(0);
});

it('records new consents for a new cycle and leaves earlier ones untouched', function () {
    $entry = WaitlistEntry::factory()->unsubscribed()->onList('beta')->create(['email' => 'user@example.com']);

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent('2026-09'));

    $cycles = $entry->subscriptions()->with('consents')->get();

    expect($cycles[0]->consents[0]->text)->toBe('Email me when early access opens.')
        ->and($cycles[1]->consents[0]->text)->toBe('Earlier wording.');

    assertWaitlistInvariants();
});

it('freezes the wording once written', function () {
    $consent = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->subscription->consents[0];

    $consent->text = 'rewritten';
    $consent->save();
})->throws(ImmutableAttributeException::class, 'immutable attribute(s) [text]');

it('refuses to delete a consent on its own', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->subscription->consents[0]->delete();
})->throws(RuntimeException::class, 'cannot be deleted individually');

it('refuses to delete a cycle on its own', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->subscription->delete();
})->throws(RuntimeException::class, 'cannot be deleted individually');

it('keeps the start of a cycle', function () {
    $subscription = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->subscription;

    $subscription->started_at = now()->addDay();
    $subscription->save();
})->throws(ImmutableAttributeException::class, 'immutable attribute(s) [started_at]');
