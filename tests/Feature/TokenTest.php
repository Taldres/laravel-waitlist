<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

it('returns the unsubscribe token issued at signup, without rotating', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $entry = $tokens['entry'];

    expect(Waitlist::unsubscribeToken($entry)->token)->toBe($tokens['unsubscribe'])
        ->and(Waitlist::unsubscribeToken($entry)->token)->toBe($tokens['unsubscribe']);
});

it('stores the unsubscribe token encrypted with a matching lookup hash', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $entry = WaitlistEntry::query()->firstOrFail();

    expect($entry->getRawOriginal('unsubscribe_token'))->not->toBe($tokens['unsubscribe'])
        ->and($entry->plainUnsubscribeToken())->toBe($tokens['unsubscribe'])
        ->and($entry->unsubscribe_token_hash)->toBe(WaitlistEntry::hashToken($tokens['unsubscribe']));
});

it('mints a fresh unsubscribe token when the stored one cannot be decrypted', function () {
    $entry = WaitlistEntry::factory()->pending()->create();
    $oldHash = $entry->unsubscribe_token_hash;

    WaitlistEntry::query()->whereKey($entry->getKey())->update(['unsubscribe_token' => 'not-decryptable']);

    $token = Waitlist::unsubscribeToken($entry->fresh())->token;

    expect($token)->not->toBeEmpty()
        ->and(WaitlistEntry::query()->firstOrFail()->unsubscribe_token_hash)->toBe(WaitlistEntry::hashToken($token))
        ->and(WaitlistEntry::query()->firstOrFail()->unsubscribe_token_hash)->not->toBe($oldHash);
});

it('builds no unsubscribe url when routes are disabled and no pattern is set', function () {
    $entry = WaitlistEntry::factory()->pending()->create();

    expect(Waitlist::unsubscribeUrl($entry))->toBeNull()
        ->and(Waitlist::listUnsubscribeHeaders($entry))->toBe([]);

    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(unsubscribe: 'https://app.test/u/{token}'));

    expect(Waitlist::unsubscribeUrl($entry))->toStartWith('https://app.test/u/');
});

it('issues a short-lived manage link, stored only as a hash, that replaces the previous one', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(manage: 'https://app.test/preferences/{token}'));
    $entry = subscribeAndCapture('beta', 'user@example.com')['entry'];

    $first = Waitlist::manageLink($entry);
    $second = Waitlist::manageLink($entry);
    $row = WaitlistEntry::query()->firstOrFail();

    expect($second->url)->toBe("https://app.test/preferences/{$second->token}")
        ->and($second->expiresAt->diffInMinutes(now()->addHour()))->toBeLessThan(1.0)
        ->and($row->manage_token_hash)->toBe(WaitlistEntry::hashToken($second->token))
        ->and(json_encode($row->getAttributes()))->not->toContain($second->token)
        ->and(Waitlist::findByManageToken($first->token))->toBeNull()
        ->and(Waitlist::findByManageToken($second->token)?->is($entry))->toBeTrue();
});

it('lets only a manage token add a purpose, and only while it is valid', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);
    $manage = manageTokenFor($tokens['entry']);

    expect(fn () => Waitlist::grantConsent($tokens['unsubscribe'], 'newsletter', '2026-10'))->toThrow(InvalidTokenException::class);

    $this->travel(61)->minutes();

    expect(fn () => Waitlist::grantConsent($manage, 'newsletter', '2026-10'))->toThrow(ExpiredTokenException::class)
        ->and($tokens['entry']->fresh()->purposes)->toBe(['waitlist']);
});

it('mails a manage link on request, never to whoever asked, at most once per cooldown', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    Event::fake([ManageLinkRequested::class]);

    expect(Waitlist::requestManageLink($tokens['unsubscribe']))->toBeTrue()
        ->and(Waitlist::requestManageLink($tokens['unsubscribe']))->toBeFalse()
        ->and(Waitlist::for('beta')->requestManageLink('user@example.com'))->toBeFalse()
        ->and(Waitlist::requestManageLink('nope'))->toBeFalse()
        ->and(Waitlist::for('beta')->requestManageLink('nobody@example.com'))->toBeFalse();

    Event::assertDispatchedTimes(ManageLinkRequested::class, 1);
    Event::assertDispatched(ManageLinkRequested::class, fn (ManageLinkRequested $event) => $event->entry->is($tokens['entry'])
        && Waitlist::findByManageToken($event->manageToken)?->is($tokens['entry']) === true);

    $this->travel(6)->minutes();

    expect(Waitlist::for('beta')->requestManageLink('USER@example.com'))->toBeTrue();
    Event::assertDispatchedTimes(ManageLinkRequested::class, 2);
});

it('mails a manage link by address only where the mailbox was proven, once per cooldown across lists', function () {
    Event::fake([ManageLinkRequested::class]);

    subscribeAndCapture('pending', 'stranger@example.com');
    $a = subscribeAndCapture('a', 'user@example.com');
    $b = subscribeAndCapture('b', 'user@example.com');
    Waitlist::confirm($a['confirm']);
    Waitlist::confirm($b['confirm']);

    expect(Waitlist::for('pending')->requestManageLink('stranger@example.com'))->toBeFalse()
        ->and(Waitlist::for('a')->requestManageLink('user@example.com'))->toBeTrue()
        ->and(Waitlist::for('b')->requestManageLink('user@example.com'))->toBeFalse()
        ->and(Waitlist::requestManageLink($b['unsubscribe']))->toBeFalse();

    Event::assertDispatchedTimes(ManageLinkRequested::class, 1);
});
