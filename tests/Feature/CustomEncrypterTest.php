<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Tests\Fixtures\FakeEncrypter;

afterEach(fn () => Model::encryptUsing(null));

it('uses a custom encrypter for every encrypted value and the lookup hash', function () {
    config()->set(ConfigKey::StoreIp->value, true);

    Waitlist::encryptUsing(new FakeEncrypter);

    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::for('beta')->find('user@example.com')->forceFill(['metadata' => ['source' => 'footer']])->save();
    Waitlist::confirm($tokens['confirm'], new RequestContext('203.0.113.7'));

    $row = DB::table('waitlist_entries')->first();
    $subkey = hash_hmac('sha256', 'laravel-waitlist:email', 'fake-key', true);

    expect($row->email)->toStartWith('fake.')
        ->and($row->metadata)->toStartWith('fake.')
        ->and($row->unsubscribe_token)->toStartWith('fake.')
        ->and(DB::table('waitlist_activity')->whereNotNull('ip')->value('ip'))->toStartWith('fake.')
        ->and($row->email_hash)->toBe(hash_hmac('sha256', 'user@example.com', $subkey));

    $entry = Waitlist::findByEmail('user@example.com')->sole();

    expect($entry->email)->toBe('user@example.com')
        ->and($entry->metadata)->toBe(['source' => 'footer'])
        ->and($entry->plainUnsubscribeToken())->toBe($tokens['unsubscribe'])
        ->and(Waitlist::unsubscribe($tokens['unsubscribe'])->id)->toBe($entry->id);
});

it('goes back to Laravel\'s encrypter when reset', function () {
    Waitlist::encryptUsing(new FakeEncrypter);
    Waitlist::encryptUsing(null);

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect(DB::table('waitlist_entries')->value('email'))->not->toStartWith('fake.')
        ->not->toContain('user@example.com')
        ->and(Waitlist::exists('user@example.com'))->toBeTrue();
});

it('follows Model::encryptUsing() when the waitlist has no encrypter of its own', function () {
    Model::encryptUsing(new FakeEncrypter('app-key'));

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect(DB::table('waitlist_entries')->value('email'))->toStartWith('fake.')->toEndWith('.app-key')
        ->and(Waitlist::findByEmail('user@example.com')->sole()->email)->toBe('user@example.com');
});

it('keeps its own encrypter apart from Model::encryptUsing()', function () {
    Model::encryptUsing(new FakeEncrypter('app-key'));
    Waitlist::encryptUsing(new FakeEncrypter('waitlist-key'));

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    expect(DB::table('waitlist_entries')->value('email'))->toEndWith('.waitlist-key')
        ->and(Waitlist::exists('user@example.com'))->toBeTrue();
});

it('cannot read values written with a different encrypter', function () {
    WaitlistEntry::factory()->create(['email' => 'user@example.com']);

    Waitlist::encryptUsing(new FakeEncrypter);

    expect(Waitlist::exists('user@example.com'))->toBeFalse()
        ->and(WaitlistEntry::query()->sole()->readableEmail())->toBeNull();
});

it('names the encrypter in effect in the processing record', function (Closure $set) {
    $set(new FakeEncrypter);

    Artisan::call('waitlist:privacy');

    expect(Artisan::output())->toContain('Encrypted with a custom encrypter ('.FakeEncrypter::class.')');
})->with([
    'Waitlist::encryptUsing()' => [fn (FakeEncrypter $encrypter) => Waitlist::encryptUsing($encrypter)],
    'Model::encryptUsing()' => [fn (FakeEncrypter $encrypter) => Model::encryptUsing($encrypter)],
]);
