<?php

declare(strict_types=1);

use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Support\Setting;

/**
 * The config file's values by key; a list is one value, a map is walked.
 *
 * @param  array<array-key, mixed>  $config
 * @return array<string, mixed>
 */
function configKeyTestLeaves(array $config, string $prefix = 'waitlist'): array
{
    $leaves = [];

    foreach ($config as $key => $value) {
        $leaves += is_array($value) && $value !== [] && ! array_is_list($value)
            ? configKeyTestLeaves($value, "{$prefix}.{$key}")
            : ["{$prefix}.{$key}" => $value];
    }

    return $leaves;
}

describe('ConfigKey', function () {
    beforeEach(function () {
        $this->file = configKeyTestLeaves(require __DIR__.'/../../config/waitlist.php');
    });

    it('names every key of config/waitlist.php, and no other', function () {
        $keys = array_keys($this->file);
        $cases = array_map(fn (ConfigKey $key) => $key->value, ConfigKey::cases());

        sort($keys);
        sort($cases);

        expect($cases)->toBe($keys);
    });

    it('has the defaults config/waitlist.php has without environment variables', function () {
        foreach (ConfigKey::cases() as $key) {
            expect($key->default())->toBe($this->file[$key->value], "{$key->name} has another default than config/waitlist.php");
        }
    });
});

describe('Setting', function () {
    it('takes the default from ConfigKey when none is passed', function () {
        foreach ([ConfigKey::RoutesEnabled, ConfigKey::SignupPerMinute, ConfigKey::MaxConfirmations, ConfigKey::DefaultList] as $key) {
            config()->set($key->value, '');
        }

        expect(Setting::enabled(ConfigKey::RoutesEnabled->value))->toBeFalse()
            ->and(Setting::integer(ConfigKey::SignupPerMinute->value))->toBe(10)
            ->and(Setting::integerOrNull(ConfigKey::MaxConfirmations->value))->toBe(5)
            ->and(Setting::string(ConfigKey::DefaultList->value))->toBe('default');
    });

    it('keeps a default that is passed', function () {
        config()->set(ConfigKey::RoutesEnabled->value, '');

        expect(Setting::enabled(ConfigKey::RoutesEnabled->value, true))->toBeTrue();
    });

    it('reads a key of another config with the default it is given', function () {
        config()->set('services.example.enabled', 'on');

        expect(Setting::enabled('services.example.enabled', false))->toBeTrue();
    });

    it('needs a default for a key it does not know', function () {
        Setting::enabled('services.example.enabled');
    })->throws(InvalidArgumentException::class, '[services.example.enabled] is not a waitlist setting; pass its default.');

    it('refuses to read a key as another type than its default', function (Closure $read, string $message) {
        expect($read)->toThrow(LogicException::class, $message);
    })->with([
        'a switch as a number' => [fn () => Setting::integer(ConfigKey::RoutesEnabled->value), '[waitlist.routes.enabled] is not read as int.'],
        'a number as a switch' => [fn () => Setting::enabled(ConfigKey::SignupPerMinute->value), '[waitlist.routes.rate_limits.signup_per_minute] is not read as bool.'],
        'a class as a number' => [fn () => Setting::integerOrNull(ConfigKey::Model->value), '[waitlist.model] is not read as int.'],
        'a nullable value as a string' => [fn () => Setting::string(ConfigKey::Connection->value), '[waitlist.connection] is not read as string.'],
    ]);

    it('hands out a value as configured, the default only for a missing key', function () {
        config()->set('waitlist.routes.limiters', ['links' => null]);

        expect(Setting::value(ConfigKey::SignupLimiter->value))->toBe('waitlist')
            ->and(Setting::value(ConfigKey::LinksLimiter->value))->toBeNull()
            ->and(Setting::value(ConfigKey::RoutesMiddleware->value))->toBe(['api']);
    });
});

describe('Page', function () {
    it('names exactly the pages a definition takes', function () {
        $parameters = array_map(
            fn (ReflectionParameter $parameter) => $parameter->getName(),
            (new ReflectionMethod(ProjectDefinition::class, 'urls'))->getParameters(),
        );

        expect($parameters)->toBe(array_map(fn (Page $page) => $page->value, Page::cases()));
    });

    it('tells the mail links from the landing pages', function () {
        $mailLinks = array_filter(Page::cases(), fn (Page $page) => $page->isMailLink());

        expect(array_values($mailLinks))->toBe([Page::Confirm, Page::Unsubscribe, Page::Manage]);
    });

    it('names the package routes the mail links fall back to after the pages', function () {
        config()->set(ConfigKey::RoutesMiddleware->value, []);

        require __DIR__.'/../../routes/waitlist.php';
        app('router')->getRoutes()->refreshNameLookups();

        foreach ([Page::Confirm, Page::Unsubscribe, Page::Manage] as $page) {
            expect(app('router')->has('waitlist.'.$page->value))->toBeTrue("no package route for {$page->name}");
        }
    });
});
