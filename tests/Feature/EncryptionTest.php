<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\BlindIndex;
use Taldres\Waitlist\Support\RequestContext;

function rawWaitlistRows(): string
{
    return collect(['waitlist_entries', 'waitlist_subscriptions', 'waitlist_activity'])
        ->flatMap(fn (string $table) => DB::table($table)->get())
        ->toJson();
}

it('stores no personal data in plain text', function () {
    config()->set(ConfigKey::StoreIp->value, true);
    config()->set(ConfigKey::StoreUserAgent->value, true);

    Waitlist::subscribe('beta', 'User@Example.com', waitlistConsent(), ['source' => 'footer-form'], new RequestContext('203.0.113.7', 'TestBrowser'));

    $raw = rawWaitlistRows();

    expect($raw)->not->toContain('user@example.com')
        ->and($raw)->not->toContain('footer-form')
        ->and($raw)->not->toContain('203.0.113.7')
        ->and($raw)->not->toContain('TestBrowser');

    $entry = WaitlistEntry::query()->firstOrFail();

    expect($entry->email)->toBe('user@example.com')
        ->and($entry->metadata)->toBe(['source' => 'footer-form'])
        ->and($entry->activity->first()->ip)->toBe('203.0.113.7');
});

it('replaces invalid UTF-8 in metadata instead of refusing it', function () {
    $entry = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), ['source' => "bad\xFFbyte", "key\xFF" => ['nested' => "x\xFF"]])->entry;

    expect($entry->fresh()->metadata)->toBe(['source' => 'bad?byte', 'key?' => ['nested' => 'x?']]);
});

it('indexes the normalized address with a hash keyed by a subkey of APP_KEY', function () {
    $entry = WaitlistEntry::factory()->create(['email' => ' User@Example.com ']);

    $subkey = hash_hmac('sha256', 'laravel-waitlist:email', base64_decode(substr((string) config('app.key'), 7)), true);

    expect($entry->email)->toBe('user@example.com')
        ->and($entry->email_hash)->toBe(hash_hmac('sha256', 'user@example.com', $subkey))
        ->and($entry->toArray())->not->toHaveKey('email_hash');
});

it('finds addresses regardless of case, on one list or across all', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('launch')->create(['email' => 'user@example.com']);

    expect(Waitlist::exists('USER@example.com'))->toBeTrue()
        ->and(Waitlist::findByEmail(' user@EXAMPLE.com'))->toHaveCount(2)
        ->and(Waitlist::for('beta')->has('User@Example.com'))->toBeTrue()
        ->and(Waitlist::exists('someone@example.com'))->toBeFalse();
});

it('keeps one entry per address and list', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('beta', 'USER@example.com', waitlistConsent());
    Waitlist::subscribe('launch', 'user@example.com', waitlistConsent());

    expect(WaitlistEntry::query()->count())->toBe(2);
});

it('keeps every address readable and findable across an APP_KEY rotation', function () {
    $oldKey = (string) config('app.key');

    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com', 'metadata' => ['source' => 'footer']]);

    rotateAppKey([$oldKey]);

    $entry = Waitlist::findByEmail('user@example.com')->sole();

    expect($entry->email)->toBe('user@example.com')
        ->and($entry->metadata)->toBe(['source' => 'footer'])
        ->and(Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->entry->id)->toBe($entry->id)
        ->and(WaitlistEntry::query()->count())->toBe(1);

    $fresh = Waitlist::subscribe('beta', 'new@example.com', waitlistConsent())->entry;

    expect($fresh->email_hash)->toBe(BlindIndex::hash('new@example.com'))
        ->and(BlindIndex::hashes('user@example.com'))->toHaveCount(2)
        ->and(BlindIndex::hashes('user@example.com')[1])->toBe($entry->email_hash);
});

it('erases entries whose key is gone through their list, without reading them', function () {
    Event::fake([EntryForgotten::class]);

    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);

    rotateAppKey();

    expect(Waitlist::exists('user@example.com'))->toBeFalse()
        ->and(Waitlist::for('beta')->forgetAll())->toBe(1)
        ->and(WaitlistEntry::query()->count())->toBe(0);

    Event::assertDispatched(EntryForgotten::class, fn (EntryForgotten $event) => $event->email === null);
});

it('mails no manage link for an address whose key is gone', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    rotateAppKey();
    Event::fake([ManageLinkRequested::class]);

    expect(Waitlist::requestManageLink($tokens['unsubscribe']))->toBeFalse();

    Event::assertNotDispatched(ManageLinkRequested::class);
});
