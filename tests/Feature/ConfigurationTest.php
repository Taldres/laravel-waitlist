<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

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

it('reads switches and numbers the way env() leaves them', function () {
    config()->set(ConfigKey::DoubleOptIn->value, 'off');
    config()->set(ConfigKey::StoreIp->value, '1');
    config()->set(ConfigKey::RoutesEnabled->value, 'no');
    config()->set(ConfigKey::MaxConfirmations->value, '3');
    config()->set(ConfigKey::ResendCooldown->value, null);

    expect(WaitlistConfig::doubleOptIn())->toBeFalse()
        ->and(WaitlistConfig::privacy()->storeIp)->toBeTrue()
        ->and(WaitlistConfig::routesEnabled())->toBeFalse()
        ->and(WaitlistConfig::confirmation()->maxConfirmations)->toBe(3)
        ->and(WaitlistConfig::confirmation()->resendCooldown)->toBeNull();
});

it('refuses an empty double opt-in variable rather than turning confirmation off', function () {
    config()->set(ConfigKey::DoubleOptIn->value, '');

    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
})->throws(InvalidConfigurationException::class, 'The waitlist.double_opt_in.enabled config must be true or false, got an empty text; if it comes from an empty variable in .env, remove the variable to keep the default.');
