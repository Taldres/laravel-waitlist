<?php

declare(strict_types=1);

use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

it('finds addresses across lists and within one', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('launch')->create(['email' => 'user@example.com']);

    expect(Waitlist::exists('user@example.com'))->toBeTrue()
        ->and(Waitlist::exists('USER@example.com'))->toBeTrue()
        ->and(Waitlist::exists('user@example.com', list: 'beta'))->toBeTrue()
        ->and(Waitlist::exists('user@example.com', list: 'other'))->toBeFalse()
        ->and(Waitlist::findByEmail('user@example.com'))->toHaveCount(2)
        ->and(Waitlist::findByEmail('user@example.com', list: 'beta'))->toHaveCount(1);
});

it('resolves tokens back to their owner without changing anything', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    $manage = manageTokenFor($tokens['entry']);

    expect(Waitlist::findByConfirmToken($tokens['confirm'])->sequence)->toBe(1)
        ->and(Waitlist::findByUnsubscribeToken($tokens['unsubscribe'])->email)->toBe('user@example.com')
        ->and(Waitlist::findByManageToken($manage)->email)->toBe('user@example.com')
        ->and(Waitlist::findByManageToken($tokens['unsubscribe']))->toBeNull()
        ->and(Waitlist::findByConfirmToken('nope'))->toBeNull()
        ->and(Waitlist::findByUnsubscribeToken('nope'))->toBeNull()
        ->and(Waitlist::findByManageToken('nope'))->toBeNull();

    $this->travel(61)->minutes();

    expect(Waitlist::findByManageToken($manage))->toBeNull();

    assertWaitlistInvariants();
});

it('counts and queries a scoped list', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->count(2)->create();
    WaitlistEntry::factory()->confirmed()->onList('launch')->create();

    expect(Waitlist::for('beta')->count())->toBe(2)
        ->and(Waitlist::for('beta')->entries()->count())->toBe(2)
        ->and(Waitlist::for('beta')->list())->toBe('beta');
});
