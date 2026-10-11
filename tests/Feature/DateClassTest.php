<?php

declare(strict_types=1);

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Facades\Waitlist;

/*
| An app may pick its date class with Date::use(), and Eloquent then returns
| that class for every date attribute, so nothing the endpoints and the export
| read may insist on Illuminate\Support\Carbon.
*/
dataset('date classes', [
    'CarbonImmutable' => [CarbonImmutable::class],
    'Carbon\Carbon' => [BaseCarbon::class],
]);

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';
});

afterEach(fn () => Date::useDefault());

it('confirms over JSON', function (string $dateClass) {
    Date::use($dateClass);
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $response = $this->postJson("/waitlist/confirm/{$tokens['confirm']}")
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Confirmed->value);

    expect($response->json('data.confirmed_at'))->not->toBeNull()
        ->and($tokens['entry']->fresh()->confirmed_at)->not->toBeNull();
})->with('date classes');

it('unsubscribes with a one-click request', function (string $dateClass) {
    Date::use($dateClass);
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect($tokens['entry']->fresh()->unsubscribed_at)->not->toBeNull();
})->with('date classes');

it('serves the preference page', function (string $dateClass) {
    Date::use($dateClass);
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    $this->getJson('/waitlist/manage/'.manageTokenFor($tokens['entry']))
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Confirmed->value);
})->with('date classes');

it('applies the purposes of the preference page', function (string $dateClass) {
    Date::use($dateClass);
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($tokens['confirm']);

    $this->putJson('/waitlist/manage/'.manageTokenFor($tokens['entry']).'/purposes', ['purposes' => waitlistConsent()])
        ->assertOk()
        ->assertJsonPath('data.purposes', ['waitlist']);
})->with('date classes');

it('exports the dates of an entry', function (string $dateClass) {
    Date::use($dateClass);
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);
    $path = sys_get_temp_dir().'/waitlist-date-class-'.uniqid().'.csv';

    try {
        $this->artisan('waitlist:export', ['list' => 'beta', '--path' => $path])->assertSuccessful();

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        $row = fgetcsv($handle, escape: '');
        fclose($handle);

        expect(array_combine($header, $row)['confirmed_at'])->not->toBe('');
    } finally {
        @unlink($path);
    }
})->with('date classes');
