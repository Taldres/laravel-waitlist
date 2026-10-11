<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Exceptions;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Config\PackageConfig;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\WaitlistServiceProvider;

describe('the defaults', function () {
    it('are those of config/waitlist.php', function () {
        $retention = WaitlistConfig::retention();

        expect(WaitlistConfig::entryModel())->toBe(WaitlistEntry::class)
            ->and(WaitlistConfig::subscriptionModel())->toBe(WaitlistSubscription::class)
            ->and(WaitlistConfig::consentModel())->toBe(WaitlistConsent::class)
            ->and(WaitlistConfig::activityModel())->toBe(WaitlistActivity::class)
            ->and(WaitlistConfig::connection())->toBeNull()
            ->and(WaitlistConfig::defaultList())->toBe('default')
            ->and(WaitlistConfig::requireWordingHash())->toBeFalse()
            ->and(WaitlistConfig::doubleOptIn())->toBeTrue()
            ->and(WaitlistConfig::confirmTokenTtl())->toBe(60 * 24 * 7)
            ->and(WaitlistConfig::resendCooldown())->toBe(5)
            ->and(WaitlistConfig::maxConfirmations())->toBe(5)
            ->and(WaitlistConfig::maxPendingPerAddress())->toBe(5)
            ->and(WaitlistConfig::manageTokenTtl())->toBe(60)
            ->and(WaitlistConfig::manageRequestCooldown())->toBe(5)
            ->and(WaitlistConfig::privacy()->storeIp)->toBeFalse()
            ->and(WaitlistConfig::privacy()->storeUserAgent)->toBeFalse()
            ->and($retention->pendingDays)->toBe(30)
            ->and($retention->unsubscribedDays)->toBe(1095)
            ->and($retention->requestMetadataDays)->toBe(30)
            ->and(WaitlistConfig::routesEnabled())->toBeFalse()
            ->and(WaitlistConfig::routeName())->toBe('waitlist.')
            ->and(WaitlistConfig::signupPerMinute())->toBe(10)
            ->and(WaitlistConfig::linkPerMinute())->toBe(10)
            ->and(WaitlistConfig::linksPerIpPerMinute())->toBe(600)
            ->and(WaitlistConfig::callerSignupPerMinute())->toBe(120)
            ->and(WaitlistConfig::guards())->toBe([null])
            ->and(WaitlistConfig::authenticationRequired())->toBeFalse()
            ->and(WaitlistConfig::clientIpHeader())->toBeNull()
            ->and(WaitlistConfig::export()->spreadsheetSafe)->toBeTrue()
            ->and(WaitlistConfig::export()->columns)->toBe(['id', 'list', 'email', 'status', 'purposes', 'confirmed_at', 'unsubscribed_at', 'metadata', 'created_at']);
    });

    it('pass the full check', function () {
        checkWaitlistConfig();
    })->throwsNoExceptions();
});

describe('values the app sets', function () {
    it('are read as configured, including the forms env() leaves', function () {
        config()->set(ConfigKey::DoubleOptIn->value, 'off');
        config()->set(ConfigKey::StoreUserAgent->value, ' YES ');
        config()->set(ConfigKey::StoreIp->value, 1);
        config()->set(ConfigKey::ManageTokenTtl->value, ' 30 ');
        config()->set(ConfigKey::ResendCooldown->value, '05');
        config()->set(ConfigKey::Connection->value, 'side');
        config()->set(ConfigKey::AuthenticationGuards->value, ['first' => 'sanctum', 'second' => null]);

        expect(WaitlistConfig::doubleOptIn())->toBeFalse()
            ->and(WaitlistConfig::privacy()->storeUserAgent)->toBeTrue()
            ->and(WaitlistConfig::privacy()->storeIp)->toBeTrue()
            ->and(WaitlistConfig::manageTokenTtl())->toBe(30)
            ->and(WaitlistConfig::resendCooldown())->toBe(5)
            ->and(WaitlistConfig::connection())->toBe('side')
            ->and(WaitlistConfig::guards())->toBe(['sanctum', null]);
    });

    it('keep false, 0 and empty lists as set', function () {
        config()->set(ConfigKey::DoubleOptIn->value, false);
        config()->set(ConfigKey::ResendCooldown->value, 0);
        config()->set(ConfigKey::RetentionPendingDays->value, '0');
        config()->set(ConfigKey::StoreIp->value, '0');
        config()->set(ConfigKey::AuthenticationGuards->value, []);

        expect(WaitlistConfig::doubleOptIn())->toBeFalse()
            ->and(WaitlistConfig::resendCooldown())->toBe(0)
            ->and(WaitlistConfig::retention()->pendingDays)->toBe(0)
            ->and(WaitlistConfig::privacy()->storeIp)->toBeFalse()
            ->and(WaitlistConfig::guards())->toBe([]);
    });

    it('take null where the setting allows it, and an empty text as null for names', function () {
        config()->set(ConfigKey::ConfirmTokenTtl->value, null);
        config()->set(ConfigKey::RetentionUnsubscribedDays->value, null);
        config()->set(ConfigKey::ClientIpHeader->value, '');

        expect(WaitlistConfig::confirmTokenTtl())->toBeNull()
            ->and(WaitlistConfig::retention()->unsubscribedDays)->toBeNull()
            ->and(WaitlistConfig::clientIpHeader())->toBeNull();
    });

    it('may leave the route prefix and name empty, for routes at the root', function () {
        config()->set(ConfigKey::RoutesPrefix->value, '');
        config()->set(ConfigKey::RoutesName->value, '');

        expect(WaitlistConfig::routeName())->toBe('');

        checkWaitlistConfig();
    });

    it('are read anew whenever the config changes', function () {
        expect(WaitlistConfig::maxConfirmations())->toBe(5);

        config()->set(ConfigKey::MaxConfirmations->value, 3);

        expect(WaitlistConfig::maxConfirmations())->toBe(3);

        config()->set(ConfigKey::MaxConfirmations->value, 5);

        expect(WaitlistConfig::maxConfirmations())->toBe(5);
    });

    it('are checked anew once the config changes', function () {
        WaitlistConfig::privacy();

        config()->set(ConfigKey::StoreIp->value, 'maybe');

        WaitlistConfig::privacy();
    })->throws(InvalidConfigurationException::class, 'The waitlist.privacy.store_ip config must be true or false, got a text it does not read.');
});

describe('a value that does not fit', function () {
    it('is refused with the key, what it must be and its type, never its value', function (ConfigKey|string $key, mixed $value, string $message) {
        config()->set($key instanceof ConfigKey ? $key->value : $key, $value);

        expect(fn () => checkWaitlistConfig())->toThrow(InvalidConfigurationException::class, $message);
    })->with([
        'an unreadable switch' => [ConfigKey::DoubleOptIn, 'maybe', 'The waitlist.double_opt_in.enabled config must be true or false, got a text it does not read.'],
        'an empty switch' => [ConfigKey::StoreIp, '', 'The waitlist.privacy.store_ip config must be true or false, got an empty text; if it comes from an empty variable in .env, remove the variable to keep the default.'],
        'a number for a switch' => [ConfigKey::StoreIp, 2, 'The waitlist.privacy.store_ip config must be true or false, got int.'],
        'an unreadable number' => [ConfigKey::MaxConfirmations, 'three', 'The waitlist.double_opt_in.max_confirmations config must be a whole number or null, got a text it does not read.'],
        'a number too large for an int' => [ConfigKey::ManageTokenTtl, '99999999999999999999', 'The waitlist.manage.token_ttl config must be a whole number, got a text it does not read.'],
        'a fraction' => [ConfigKey::SignupPerMinute, 2.5, 'The waitlist.routes.rate_limits.signup_per_minute config must be a whole number, got float.'],
        'null where none is allowed' => [ConfigKey::ManageTokenTtl, null, 'The waitlist.manage.token_ttl config must be a whole number, got null.'],
        'an empty number' => [ConfigKey::LinkPerMinute, '', 'The waitlist.routes.rate_limits.link_per_minute config must be a whole number, got an empty text; if it comes from an empty variable in .env, remove the variable to keep the default.'],
        'a negative retention period' => [ConfigKey::RetentionPendingDays, -1, 'The waitlist.retention.pending_days config must be at least 0.'],
        'a confirm link that expires at once' => [ConfigKey::ConfirmTokenTtl, 0, 'The waitlist.double_opt_in.token_ttl config must be at least 1.'],
        'no cap left for confirmation mails' => [ConfigKey::MaxConfirmations, 0, 'The waitlist.double_opt_in.max_confirmations config must be at least 1.'],
        'a manage link that expires at once' => [ConfigKey::ManageTokenTtl, 0, 'The waitlist.manage.token_ttl config must be at least 1.'],
        'a rate limit that refuses everyone' => [ConfigKey::CallerSignupPerMinute, '0', 'The waitlist.routes.rate_limits.caller_signup_per_minute config must be at least 1.'],
        'a list for a text' => [ConfigKey::DefaultList, ['beta'], 'The waitlist.default_list config must be a text, got array.'],
        'an empty list name' => [ConfigKey::DefaultList, '', 'The waitlist.default_list config must be a text, got an empty text; if it comes from an empty variable in .env, remove the variable to keep the default.'],
        'a number for a name' => [ConfigKey::Connection, 5, 'The waitlist.connection config must be a text or null, got int.'],
        'a prefix with a placeholder' => [ConfigKey::RoutesPrefix, 'p/{project}/waitlist', 'The waitlist.routes.prefix config must be a path without placeholders, such as waitlist or api/waitlist, got one with braces.'],
        'middleware that is not a name' => [ConfigKey::RoutesMiddleware, ['api', 1], 'The waitlist.routes.middleware config must be a list of middleware names, got array.'],
        'middleware as a map' => [ConfigKey::SignupMiddleware, ['csrf' => 'web'], 'The waitlist.routes.group_middleware.signup config must be a list of middleware names, got array.'],
        'null for middleware' => [ConfigKey::LinksMiddleware, null, 'The waitlist.routes.group_middleware.links config must be a list of middleware names, got null.'],
        'a guard that is not a name' => [ConfigKey::AuthenticationGuards, ['web', 5], 'The waitlist.authentication.guards config must list guard names, or null for the default guard, got array.'],
        'columns that are not names' => [ConfigKey::ExportColumns, ['email', 5], 'The waitlist.export.columns config must be a list of column names, got array.'],
        'columns that may not be exported' => [ConfigKey::ExportColumns, ['email', 'confirm_token_hash'], 'The waitlist.export.columns config lists columns that may not be exported: confirm_token_hash.'],
        'a model that is not a waitlist model' => [ConfigKey::ConsentModel, stdClass::class, 'The waitlist.consent_model config must point to a '.WaitlistConsent::class.' subclass.'],
        'a group that is not an array' => ['waitlist.double_opt_in', 'on', 'The waitlist.double_opt_in config must be an array of settings, got string.'],
        'a nested group that is null' => ['waitlist.routes.rate_limits', null, 'The waitlist.routes.rate_limits config must be an array of settings, got null.'],
    ]);

    it('is refused for the whole config too', function () {
        config()->set('waitlist', 'off');

        WaitlistConfig::defaultList();
    })->throws(InvalidConfigurationException::class, 'The waitlist config must be an array of settings, got string.');

    it('is reported as missing rather than read from the package, as from a stale config cache', function () {
        config()->set('waitlist', Arr::except(config()->array('waitlist'), 'manage'));

        WaitlistConfig::manageTokenTtl();
    })->throws(InvalidConfigurationException::class, 'The waitlist.manage.token_ttl config is missing. If the config is cached, cache it again with php artisan config:cache.');
});

describe('a name', function () {
    it('is read as unset when it is empty, where null is allowed', function () {
        config()->set(ConfigKey::Connection->value, '');
        config()->set(ConfigKey::ClientIpHeader->value, '');

        expect(WaitlistConfig::connection())->toBeNull()
            ->and(WaitlistConfig::clientIpHeader())->toBeNull();

        checkWaitlistConfig();
    });

    it('is refused for a route prefix with a placeholder by the full check as well, and read without one', function () {
        config()->set(ConfigKey::RoutesPrefix->value, 'p/{project}/waitlist');

        expect(fn () => checkWaitlistConfig())->toThrow(InvalidConfigurationException::class, ConfigKey::RoutesPrefix->value);

        config()->set(ConfigKey::RoutesPrefix->value, 'p/project/waitlist');

        expect(WaitlistConfig::routePrefix())->toBe('p/project/waitlist');
    });
});

describe('a mistake in one setting', function () {
    it('never keeps a person from unsubscribing', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com');

        config()->set(ConfigKey::ExportColumns->value, ['email', 'unsubscribe_token']);
        bindUnreadable(SpamProtector::class);
        config()->set(ConfigKey::SignupPerMinute->value, 'many');
        config()->set(ConfigKey::MaxPendingPerAddress->value, 0);
        config()->set(ConfigKey::DefaultList->value, '');

        expect(Waitlist::unsubscribe($tokens['unsubscribe'])->status)->toBe(EntryStatus::Unsubscribed);
    });

    it('never keeps a person from unsubscribing over the routes either', function () {
        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);
        require __DIR__.'/../../routes/waitlist.php';

        $tokens = subscribeAndCapture('beta', 'user@example.com');

        config()->set(ConfigKey::ExportColumns->value, ['email', 'unsubscribe_token']);
        bindUnreadable(SpamProtector::class);
        config()->set(ConfigKey::SignupPerMinute->value, 'many');
        config()->set(ConfigKey::CallerSignupPerMinute->value, 0);

        $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")
            ->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Unsubscribed->value);
    });
});

describe('a mistake in the config while the app boots', function () {
    beforeEach(function () {
        // Before the routes are on, which the confirm links would point at.
        $this->tokens = subscribeAndCapture('beta', 'user@example.com');

        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);
        $this->signUp = fn () => $this->postJson('/waitlist', ['email' => 'other@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()]);

        Exceptions::fake();
    });

    it('leaves out only the routes of its group, and reports the setting', function (ConfigKey $key, mixed $value, string $message) {
        config()->set($key->value, $value);

        (new WaitlistServiceProvider(app()))->boot();

        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")
            ->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Unsubscribed->value);
        ($this->signUp)()->assertNotFound();
        $this->getJson('/waitlist/purposes?list=beta')->assertNotFound();

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => $exception->getMessage() === $message);
    })->with([
        'signup middleware' => [ConfigKey::SignupMiddleware, null, 'The waitlist.routes.group_middleware.signup config must be a list of middleware names, got null.'],
    ]);

    it('leaves out the token links of a group that does not read, and leaves the signup alone', function (ConfigKey $key, mixed $value, string $message) {
        // The pages are the project's own, so the signup builds no package link.
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
            confirm: 'https://app.test/confirm/{token}',
            unsubscribe: 'https://app.test/leave/{token}',
        ));
        config()->set($key->value, $value);

        (new WaitlistServiceProvider(app()))->boot();

        ($this->signUp)()->assertStatus(202);
        $this->getJson('/waitlist/purposes?list=beta')->assertOk();
        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")->assertNotFound();
        $this->getJson("/waitlist/confirm/{$this->tokens['confirm']}")->assertNotFound();

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => $exception->getMessage() === $message);
    })->with([
        'links middleware' => [ConfigKey::LinksMiddleware, null, 'The waitlist.routes.group_middleware.links config must be a list of middleware names, got null.'],
    ]);

    it('boots with a key missing from a stale config cache, so artisan can cache it again', function () {
        $config = config()->array('waitlist');
        unset($config['routes']['group_middleware']['signup']);
        config()->set('waitlist', $config);

        (new WaitlistServiceProvider(app()))->boot();

        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")->assertOk();
        ($this->signUp)()->assertNotFound();

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => str_starts_with($exception->getMessage(), 'The waitlist.routes.group_middleware.signup config is missing.'));
    });

    it('leaves out every route when a setting that places them does not read', function (ConfigKey $key, mixed $value, string $message) {
        config()->set($key->value, $value);

        (new WaitlistServiceProvider(app()))->boot();

        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")->assertNotFound();
        $this->getJson('/waitlist/purposes?list=beta')->assertNotFound();

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => $exception->getMessage() === $message);
    })->with([
        'their switch' => [ConfigKey::RoutesEnabled, 'maybe', 'The waitlist.routes.enabled config must be true or false, got a text it does not read.'],
        'their prefix' => [ConfigKey::RoutesPrefix, ['waitlist'], 'The waitlist.routes.prefix config must be a text, got array.'],
        'a prefix with a placeholder' => [ConfigKey::RoutesPrefix, 'p/{project}/waitlist', 'The waitlist.routes.prefix config must be a path without placeholders, such as waitlist or api/waitlist, got one with braces.'],
        'their middleware' => [ConfigKey::RoutesMiddleware, 5, 'The waitlist.routes.middleware config must be a list of middleware names, got int.'],
    ]);

    it('reports a setting once, however many routes it belongs to', function () {
        config()->set(ConfigKey::SignupMiddleware->value, null);

        (new WaitlistServiceProvider(app()))->boot();

        Exceptions::assertReportedCount(1);
    });
});

describe('a mistake in the retention config', function () {
    it('is reported when prune runs, not before', function () {
        config()->set(ConfigKey::RetentionPendingDays->value, -1);

        expect(fn () => app(PruneEntries::class)())->toThrow(InvalidConfigurationException::class, 'The waitlist.retention.pending_days config must be at least 0.');
    });
});

describe('a mistake in the resend config', function () {
    it('never keeps an issued confirm link from working', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com');

        config()->set(ConfigKey::ResendCooldown->value, -1);
        config()->set(ConfigKey::MaxConfirmations->value, 'many');

        expect(Waitlist::confirm($tokens['confirm'])->status)->toBe(EntryStatus::Confirmed);
    });
});

describe('merging the app config with the package config', function () {
    it('fills in what is missing, following the package structure', function () {
        $merged = PackageConfig::merge([
            'routes' => ['enabled' => true, 'middleware' => [], 'group_middleware' => []],
            'double_opt_in' => 'on',
            'export' => ['columns' => ['email']],
            'retention' => ['pending_days' => null, 'unsubscribed_days' => 0],
            'privacy' => ['store_ip' => false],
        ]);

        expect($merged)->toBeArray()
            ->and($merged['routes']['enabled'])->toBeTrue()
            ->and($merged['routes']['middleware'])->toBe([])
            ->and($merged['routes']['group_middleware'])->toBe(['signup' => [], 'links' => []])
            ->and($merged['routes']['rate_limits']['signup_per_minute'])->toBe(10)
            ->and($merged['double_opt_in'])->toBe('on')
            ->and($merged['export']['columns'])->toBe(['email'])
            ->and($merged['retention']['pending_days'])->toBeNull()
            ->and($merged['retention']['unsubscribed_days'])->toBe(0)
            ->and($merged['privacy']['store_ip'])->toBeFalse()
            ->and($merged['manage']['token_ttl'])->toBe(60);
    });

    it('leaves a config that is not an array to be refused', function () {
        expect(PackageConfig::merge('off'))->toBe('off');
    });

    it('still fills an empty group, at any depth, as one that sets nothing', function () {
        $defaults = PackageConfig::merge([]);

        $merged = PackageConfig::merge(['routes' => [], 'export' => [], 'double_opt_in' => ['enabled' => false], 'retention' => ['pending_days' => []]]);

        expect($merged['routes'])->toBe($defaults['routes'])
            ->and($merged['export'])->toBe($defaults['export'])
            ->and($merged['double_opt_in']['enabled'])->toBeFalse()
            ->and($merged['double_opt_in']['token_ttl'])->toBe($defaults['double_opt_in']['token_ttl'])
            ->and(PackageConfig::merge(['routes' => ['rate_limits' => []]])['routes']['rate_limits'])->toBe($defaults['routes']['rate_limits']);

        config()->set('waitlist', PackageConfig::merge(['routes' => [], 'export' => []]));

        checkWaitlistConfig();
    });

    it('runs when the provider registers, and not on a cached config', function () {
        config()->set('waitlist', ['routes' => ['enabled' => true]]);

        app()->instance('config_loaded_from_cache', true);
        (new WaitlistServiceProvider(app()))->register();

        expect(config('waitlist.routes'))->toBe(['enabled' => true]);

        app()->instance('config_loaded_from_cache', false);
        (new WaitlistServiceProvider(app()))->register();

        expect(config('waitlist.routes.prefix'))->toBe('waitlist')
            ->and(config('waitlist.double_opt_in.enabled'))->toBeTrue();
    });

    it('keeps the keys the package does not know, at every depth', function () {
        $merged = PackageConfig::merge([
            'legacy' => ['a' => 1],
            'routes' => ['custom' => true, 'rate_limits' => ['burst' => 3], 'group_middleware' => ['third' => ['mine']]],
        ]);

        expect($merged['legacy'])->toBe(['a' => 1])
            ->and($merged['routes']['custom'])->toBeTrue()
            ->and($merged['routes']['rate_limits']['burst'])->toBe(3)
            ->and($merged['routes']['rate_limits']['signup_per_minute'])->toBe(10)
            ->and($merged['routes']['group_middleware'])->toEqual(['signup' => [], 'links' => [], 'third' => ['mine']]);
    });

    it('keeps a group the app sets to something that is no array, so that it is refused when read', function (string $path, mixed $value) {
        $app = [];
        Arr::set($app, $path, $value);

        $merged = PackageConfig::merge($app);

        expect(Arr::get($merged, $path))->toBe($value);

        config()->set('waitlist', $merged);

        expect(fn () => checkWaitlistConfig())
            ->toThrow(InvalidConfigurationException::class, "The waitlist.{$path} config must be an array of settings, got ".get_debug_type($value).'.');
    })->with(function () {
        foreach (waitlistConfigGroups() as $path) {
            yield "{$path} as null" => [$path, null];
            yield "{$path} as a text" => [$path, 'on'];
        }
    });

    it('has nothing left to fill in what it filled already, however often the provider registers', function () {
        $app = ['routes' => ['enabled' => true, 'group_middleware' => ['signup' => null]], 'export' => ['columns' => ['id']], 'manage' => ['token_ttl' => [1]], 'legacy' => 1];
        $once = PackageConfig::merge($app);

        expect(PackageConfig::merge($once))->toBe($once);

        config()->set('waitlist', $app);

        (new WaitlistServiceProvider(app()))->register();
        (new WaitlistServiceProvider(app()))->register();

        expect(config('waitlist'))->toBe($once);
    });

    it('is written by config:cache whole, with the env() of that moment, and read back untouched', function () {
        $repository = Env::getRepository();
        $repository->set('WAITLIST_STORE_IP', 'true');

        try {
            config()->set('waitlist', ['routes' => ['enabled' => true, 'middleware' => ['web']]]);
            (new WaitlistServiceProvider(app()))->register();

            // What config:cache writes and the next boot requires back.
            $cached = eval('return '.var_export(config('waitlist'), true).';');

            $repository->clear('WAITLIST_STORE_IP');
            $repository->set('WAITLIST_STORE_IP', 'false');

            config()->set('waitlist', $cached);
            app()->instance('config_loaded_from_cache', true);
            (new WaitlistServiceProvider(app()))->register();

            checkWaitlistConfig();

            expect(config('waitlist'))->toBe($cached)
                ->and(WaitlistConfig::privacy()->storeIp)->toBeTrue()
                ->and(WaitlistConfig::routeMiddleware())->toBe(['web'])
                ->and(WaitlistConfig::manageTokenTtl())->toBe(60);
        } finally {
            $repository->clear('WAITLIST_STORE_IP');
        }
    });
});

/**
 * Every group of config/waitlist.php as the path its keys live under.
 *
 * @return list<string>
 */
function waitlistConfigGroups(): array
{
    $groups = [];

    foreach (ConfigKey::cases() as $key) {
        $segments = explode('.', substr($key->value, strlen('waitlist.')));

        for ($depth = 1; $depth < count($segments); $depth++) {
            $groups[] = implode('.', array_slice($segments, 0, $depth));
        }
    }

    return array_values(array_unique($groups));
}

abstract class WaitlistConfigAbstractEntry extends WaitlistEntry {}

abstract class WaitlistConfigAbstractSubscription extends WaitlistSubscription {}

abstract class WaitlistConfigAbstractConsent extends WaitlistConsent {}

abstract class WaitlistConfigAbstractActivity extends WaitlistActivity {}

class WaitlistConfigReplacementEntry extends WaitlistEntry {}

describe('a model that cannot be instantiated', function () {
    it('is refused for every model setting', function (ConfigKey $key, string $accessor, string $abstract) {
        config()->set($key->value, $abstract);

        expect(fn () => WaitlistConfig::{$accessor}())->toThrow(InvalidConfigurationException::class, "The {$key->value} config must point to a ")
            ->and(fn () => WaitlistConfig::{$accessor}())->toThrow(InvalidConfigurationException::class, 'subclass that is not abstract.');
    })->with([
        'entry' => [ConfigKey::Model, 'entryModel', WaitlistConfigAbstractEntry::class],
        'subscription' => [ConfigKey::SubscriptionModel, 'subscriptionModel', WaitlistConfigAbstractSubscription::class],
        'consent' => [ConfigKey::ConsentModel, 'consentModel', WaitlistConfigAbstractConsent::class],
        'activity' => [ConfigKey::ActivityModel, 'activityModel', WaitlistConfigAbstractActivity::class],
    ]);

    it('is refused before a signup meets it', function () {
        config()->set(ConfigKey::Model->value, WaitlistConfigAbstractEntry::class);

        Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());
    })->throws(InvalidConfigurationException::class, 'subclass that is not abstract.');

    it('leaves the model itself and its subclasses alone', function () {
        config()->set(ConfigKey::Model->value, WaitlistConfigReplacementEntry::class);

        expect(WaitlistConfig::entryModel())->toBe(WaitlistConfigReplacementEntry::class);
    });
});

describe('a config that is missing altogether', function () {
    it('points to config:cache, as a single missing key does', function () {
        app()->instance('config_loaded_from_cache', true);
        config()->offsetUnset('waitlist');

        expect(fn () => WaitlistConfig::defaultList())->toThrow(InvalidConfigurationException::class, 'The waitlist config must be an array of settings, got null. If the config is cached, cache it again with php artisan config:cache.');
    });

    it('is told apart from a config of the wrong type', function () {
        config()->set('waitlist', 'off');

        expect(fn () => WaitlistConfig::defaultList())->toThrow(InvalidConfigurationException::class, 'The waitlist config must be an array of settings, got string.');
    });
});

describe('a setting of its own', function () {
    it('is read without the settings it is grouped with', function () {
        config()->set(ConfigKey::MaxPendingPerAddress->value, 'soon');
        config()->set(ConfigKey::ManageRequestCooldown->value, 'soon');
        config()->set(ConfigKey::RetentionRequestMetadataDays->value, 'soon');

        expect(WaitlistConfig::confirmTokenTtl())->toBe(60 * 24 * 7)
            ->and(WaitlistConfig::resendCooldown())->toBe(5)
            ->and(WaitlistConfig::maxConfirmations())->toBe(5)
            ->and(WaitlistConfig::manageTokenTtl())->toBe(60)
            ->and(WaitlistConfig::pendingDays())->toBe(30)
            ->and(WaitlistConfig::unsubscribedDays())->toBe(1095)
            ->and(fn () => WaitlistConfig::maxPendingPerAddress())->toThrow(InvalidConfigurationException::class, ConfigKey::MaxPendingPerAddress->value)
            ->and(fn () => WaitlistConfig::manageRequestCooldown())->toThrow(InvalidConfigurationException::class, ConfigKey::ManageRequestCooldown->value)
            ->and(fn () => WaitlistConfig::requestMetadataDays())->toThrow(InvalidConfigurationException::class, ConfigKey::RetentionRequestMetadataDays->value);
    });

    it('is still among the settings a check reads', function (ConfigKey $key) {
        config()->set($key->value, 'soon');

        $messages = implode("\n", array_map(fn (Throwable $problem): string => $problem->getMessage(), WaitlistConfig::problems()));

        expect($messages)->toContain($key->value);
    })->with([
        ConfigKey::ConfirmTokenTtl,
        ConfigKey::MaxPendingPerAddress,
        ConfigKey::ManageRequestCooldown,
        ConfigKey::RetentionUnsubscribedDays,
    ]);
});
