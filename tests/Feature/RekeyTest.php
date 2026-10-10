<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Taldres\Waitlist\Actions\RekeyEntries;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\BlindIndex;
use Taldres\Waitlist\Support\RequestContext;

it('rewrites everything under the current key, so the old one can be retired', function () {
    config()->set(ConfigKey::StoreIp->value, true);
    config()->set(ConfigKey::StoreUserAgent->value, true);
    $oldKey = (string) config('app.key');

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), ['source' => 'footer'], new RequestContext('203.0.113.7', 'TestBrowser'));
    $unsubscribe = Waitlist::unsubscribeToken(WaitlistEntry::query()->sole())->token;

    rotateAppKey([$oldKey]);
    $newKey = (string) config('app.key');

    $this->artisan('waitlist:rekey')
        ->expectsOutputToContain('Rewrote 1 entries and 2 log rows')
        ->assertSuccessful();

    rotateAppKey([], $newKey);

    $entry = Waitlist::findByEmail('user@example.com')->sole();

    expect($entry->email)->toBe('user@example.com')
        ->and($entry->email_hash)->toBe(BlindIndex::hash('user@example.com'))
        ->and($entry->metadata)->toBe(['source' => 'footer'])
        ->and(Waitlist::unsubscribeToken($entry)->token)->toBe($unsubscribe)
        ->and(Waitlist::personalData('user@example.com')->sole()->toArray()['activity'][0])
        ->toMatchArray(['ip' => '203.0.113.7', 'user_agent' => 'TestBrowser']);
});

it('leaves updated_at alone: a new key is no change to the person\'s data', function () {
    $oldKey = (string) config('app.key');
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
    $before = WaitlistEntry::query()->sole()->updated_at;

    $this->travel(1)->day();
    rotateAppKey([$oldKey]);

    $this->artisan('waitlist:rekey')->assertSuccessful();

    expect(WaitlistEntry::query()->sole()->updated_at->equalTo($before))->toBeTrue();
});

it('leaves rows it cannot decrypt alone and says so', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
    Waitlist::subscribe('beta', 'lost@example.com', waitlistConsent());
    WaitlistEntry::query()->whereKey(Waitlist::findByEmail('lost@example.com')->sole()->id)->update(['email' => 'gone']);

    $this->artisan('waitlist:rekey')
        ->expectsOutputToContain('Rewrote 1 entries')
        ->expectsOutputToContain('1 entries could not be decrypted')
        ->assertSuccessful();

    expect(DB::table('waitlist_entries')->where('email', 'gone')->count())->toBe(1);
});

it('never writes request metadata back that an erasure cleared in the meantime', function () {
    config()->set(ConfigKey::StoreIp->value, true);
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent(), context: new RequestContext('203.0.113.7'));

    // Read by a rekey run, then erased before the run writes it back.
    $stale = WaitlistActivity::query()->whereNotNull('ip')->firstOrFail();
    Waitlist::forget('user@example.com');

    $rewritten = (fn (WaitlistActivity $row) => $this->rekeyActivity($row))->call(app(RekeyEntries::class), $stale);

    expect($rewritten)->toBeFalse()
        ->and(DB::table('waitlist_activity')->whereNotNull('ip')->count())->toBe(0);
});
