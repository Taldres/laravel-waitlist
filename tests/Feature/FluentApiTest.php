<?php

declare(strict_types=1);

use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\ScopedWaitlist;
use Taldres\Waitlist\WaitlistManager;

it('adds, finds and removes through the scoped api', function () {
    $result = Waitlist::for('beta')->add('user@example.com', waitlistConsent());

    expect($result->entry->list)->toBe('beta')
        ->and(Waitlist::for('beta')->has('user@example.com'))->toBeTrue()
        ->and(Waitlist::for('beta')->find('USER@example.com')->id)->toBe($result->entry->id)
        ->and(Waitlist::for('beta')->has('nobody@example.com'))->toBeFalse();

    Waitlist::for('beta')->unsubscribe('user@example.com');

    expect(Waitlist::for('beta')->find('user@example.com')->status)->toBe(EntryStatus::Unsubscribed);

    expect(Waitlist::for('beta')->forget('user@example.com'))->toBe(1)
        ->and(Waitlist::for('beta')->has('user@example.com'))->toBeFalse();
});

it('is macroable on both the manager and a scoped list', function () {
    WaitlistManager::macro('confirmedCount', fn (string $list): int => WaitlistEntry::query()
        ->onList($list)
        ->where('status', EntryStatus::Confirmed)
        ->count());

    ScopedWaitlist::macro('newest', fn (): ?WaitlistEntry => $this->entries()->latest('created_at')->first());

    WaitlistEntry::factory()->confirmed()->onList('beta')->count(2)->create();

    expect(Waitlist::confirmedCount('beta'))->toBe(2)
        ->and(Waitlist::for('beta')->newest())->not->toBeNull();
});
