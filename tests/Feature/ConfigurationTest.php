<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Actions\ResendConfirmation;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\Setting;

class CustomEntry extends WaitlistEntry {}

class CustomSubscription extends WaitlistSubscription {}

class CustomActivity extends WaitlistActivity {}

class UppercaseNormalizer implements EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}

it('uses the configured models throughout a full cycle', function () {
    config()->set(ConfigKey::Model->value, CustomEntry::class);
    config()->set(ConfigKey::SubscriptionModel->value, CustomSubscription::class);
    config()->set(ConfigKey::ActivityModel->value, CustomActivity::class);

    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    expect(CustomEntry::query()->firstOrFail())->toBeInstanceOf(CustomEntry::class)
        ->and(CustomSubscription::query()->firstOrFail())->toBeInstanceOf(CustomSubscription::class)
        ->and(CustomActivity::query()->firstOrFail())->toBeInstanceOf(CustomActivity::class)
        ->and(Waitlist::findByUnsubscribeToken($tokens['unsubscribe']))->toBeInstanceOf(CustomEntry::class);
});

it('rejects a model that is not a waitlist model', function () {
    config()->set(ConfigKey::Model->value, stdClass::class);

    Waitlist::exists('user@example.com');
})->throws(InvalidArgumentException::class, ConfigKey::Model->value);

it('rejects a subscription model that is not a waitlist subscription', function () {
    config()->set(ConfigKey::SubscriptionModel->value, stdClass::class);

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
})->throws(InvalidArgumentException::class, ConfigKey::SubscriptionModel->value);

it('uses the configured email normalizer', function () {
    config()->set(ConfigKey::EmailNormalizer->value, UppercaseNormalizer::class);

    expect(Waitlist::subscribe('beta', ' USER@Example.com ', waitlistConsent())->entry->email)->toBe('user@example.com');
});

it('rejects a configured class that does not implement its contract', function () {
    config()->set(ConfigKey::EmailNormalizer->value, stdClass::class);

    app(EmailNormalizer::class);
})->throws(InvalidArgumentException::class, ConfigKey::EmailNormalizer->value);

it('runs every table on the configured connection', function () {
    config()->set('database.connections.waitlist_a', config('database.connections.testing'));
    config()->set(ConfigKey::Connection->value, 'waitlist_a');

    expect((new WaitlistEntry)->getConnectionName())->toBe('waitlist_a')
        ->and((new WaitlistSubscription)->getConnectionName())->toBe('waitlist_a')
        ->and((new WaitlistActivity)->getConnectionName())->toBe('waitlist_a');
});

it('creates the tables on the configured connection when migrating', function () {
    config()->set('database.connections.side', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    config()->set(ConfigKey::Connection->value, 'side');
    // Own migrations repository: on a server database the default one outlives the test.
    config()->set('database.migrations', $repository = 'migrations_'.Str::lower(Str::random(8)));

    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();

    expect(Schema::connection('side')->hasTable('waitlist_entries'))->toBeTrue()
        ->and(Schema::connection('side')->hasTable('waitlist_activity'))->toBeTrue();

    Schema::dropIfExists($repository);
});

it('reads switches the way env() leaves them, keeping the default when empty', function (string $key, mixed $value, bool $expected) {
    config()->set($key, $value);

    $default = $key === ConfigKey::DoubleOptIn->value;

    expect(Setting::enabled($key, $default))->toBe($expected);
})->with([
    'empty double opt-in stays on' => [ConfigKey::DoubleOptIn->value, '', true],
    'double opt-in "off"' => [ConfigKey::DoubleOptIn->value, 'off', false],
    'unreadable double opt-in stays on' => [ConfigKey::DoubleOptIn->value, 'maybe', true],
    'store_ip "off" stays off' => [ConfigKey::StoreIp->value, 'off', false],
    'store_ip "1"' => [ConfigKey::StoreIp->value, '1', true],
    'routes "no" stay off' => [ConfigKey::RoutesEnabled->value, 'no', false],
]);

it('reads numbers the way env() leaves them: empty keeps the default, null switches off', function (mixed $value, ?int $expected) {
    config()->set(ConfigKey::MaxConfirmations->value, $value);

    expect(Setting::integerOrNull(ConfigKey::MaxConfirmations->value, 5))->toBe($expected)
        ->and(Setting::integer(ConfigKey::MaxConfirmations->value, 5))->toBe($expected ?? 5);
})->with([
    'a number' => [3, 3],
    'a numeric string, as from .env' => ['3', 3],
    'empty' => ['', 5],
    'unreadable' => ['three', 5],
    'null' => [null, null],
]);

it('keeps the package defaults when retention and resend variables are empty', function () {
    foreach ([ConfigKey::RetentionPendingDays, ConfigKey::RetentionUnsubscribedDays, ConfigKey::RetentionRequestMetadataDays] as $key) {
        config()->set($key->value, '');
    }
    config()->set(ConfigKey::MaxConfirmations->value, '');

    expect(PruneEntries::days(ConfigKey::RetentionPendingDays->value))->toBe(30)
        ->and(PruneEntries::days(ConfigKey::RetentionUnsubscribedDays->value))->toBe(1095)
        ->and(PruneEntries::days(ConfigKey::RetentionRequestMetadataDays->value))->toBe(30)
        ->and(ResendConfirmation::maxConfirmations())->toBe(5);
});

it('keeps asking for confirmation when its variable is empty', function () {
    config()->set(ConfigKey::DoubleOptIn->value, '');

    $entry = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent())->entry;

    expect($entry->status)->toBe(EntryStatus::Pending);
});
