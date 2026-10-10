<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Taldres\Waitlist\Config\PackageConfig;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

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

    it('is read by the full check, which names it when it holds something unreadable', function (ConfigKey $key) {
        config()->set($key->value, new stdClass);

        expect(fn () => WaitlistConfig::check())->toThrow(InvalidConfigurationException::class, "The {$key->value} config ");
    })->with(ConfigKey::cases());

    it('is reported by the full check when it is missing, as from a stale config cache', function (ConfigKey $key) {
        $config = config()->array('waitlist');
        Arr::forget($config, substr($key->value, strlen('waitlist.')));
        config()->set('waitlist', $config);

        expect(fn () => WaitlistConfig::check())
            ->toThrow(InvalidConfigurationException::class, "The {$key->value} config is missing. If the config is cached, cache it again with php artisan config:cache.");
    })->with(ConfigKey::cases());

    // Several defaults are equal (5, 10, false), so only distinct values show a setting read from another one's place.
    it('is read from its own place', function () {
        RateLimiter::for('mine-signup', fn () => Limit::perMinute(1));
        RateLimiter::for('mine-links', fn () => Limit::perMinute(1));

        foreach ([
            'connection' => 'side',
            'default_list' => 'beta',
            'wording.require_hash' => 'yes',
            'double_opt_in.enabled' => 'no',
            'double_opt_in.token_ttl' => 101,
            'double_opt_in.resend_cooldown' => 102,
            'double_opt_in.max_confirmations' => 103,
            'double_opt_in.max_pending_per_address' => 104,
            'double_opt_in.invalidate_confirm_token_after_confirmation' => 'on',
            'manage.token_ttl' => 105,
            'manage.request_cooldown' => 106,
            'privacy.store_ip' => 'on',
            'privacy.store_user_agent' => 'off',
            'retention.pending_days' => 107,
            'retention.unsubscribed_days' => 108,
            'retention.request_metadata_days' => 109,
            'retention.schedule' => '5 4 * * *',
            'routes.enabled' => 'on',
            'routes.prefix' => 'lists',
            'routes.name' => 'lists.',
            'routes.middleware' => ['web'],
            'routes.group_middleware.signup' => ['csrf'],
            'routes.group_middleware.links' => ['signed'],
            'routes.limiters.signup' => 'mine-signup',
            'routes.limiters.links' => 'mine-links',
            'routes.rate_limits.signup_per_minute' => 111,
            'routes.rate_limits.link_per_minute' => 112,
            'routes.rate_limits.links_per_ip_per_minute' => 113,
            'routes.rate_limits.caller_signup_per_minute' => 114,
            'authentication.guards' => ['sanctum'],
            'authentication.required' => 'yes',
            'authentication.client_ip_header' => 'X-Visitor-Ip',
            'export.spreadsheet_safe' => 'off',
            'export.columns' => ['email', 'id'],
        ] as $path => $value) {
            config()->set("waitlist.{$path}", $value);
        }

        $confirmation = WaitlistConfig::confirmation();
        $manage = WaitlistConfig::manage();
        $privacy = WaitlistConfig::privacy();
        $retention = WaitlistConfig::retention();
        $export = WaitlistConfig::export();

        expect(WaitlistConfig::connection())->toBe('side')
            ->and(WaitlistConfig::defaultList())->toBe('beta')
            ->and(WaitlistConfig::requireWordingHash())->toBeTrue()
            ->and(WaitlistConfig::doubleOptIn())->toBeFalse()
            ->and([$confirmation->tokenTtl, $confirmation->resendCooldown, $confirmation->maxConfirmations, $confirmation->maxPendingPerAddress])->toBe([101, 102, 103, 104])
            ->and(WaitlistConfig::singleUseConfirmTokens())->toBeTrue()
            ->and([$manage->tokenTtl, $manage->requestCooldown])->toBe([105, 106])
            ->and([$privacy->storeIp, $privacy->storeUserAgent])->toBe([true, false])
            ->and([$retention->pendingDays, $retention->unsubscribedDays, $retention->requestMetadataDays])->toBe([107, 108, 109])
            ->and(WaitlistConfig::pruneSchedule())->toBe('5 4 * * *')
            ->and(WaitlistConfig::routesEnabled())->toBeTrue()
            ->and([WaitlistConfig::routePrefix(), WaitlistConfig::routeName()])->toBe(['lists', 'lists.'])
            ->and([WaitlistConfig::routeMiddleware(), WaitlistConfig::signupMiddleware(), WaitlistConfig::linksMiddleware()])->toBe([['web'], ['csrf'], ['signed']])
            ->and([WaitlistConfig::signupLimiter(), WaitlistConfig::linksLimiter()])->toBe(['mine-signup', 'mine-links'])
            ->and([WaitlistConfig::signupPerMinute(), WaitlistConfig::linkPerMinute(), WaitlistConfig::linksPerIpPerMinute(), WaitlistConfig::callerSignupPerMinute()])->toBe([111, 112, 113, 114])
            ->and(WaitlistConfig::guards())->toBe(['sanctum'])
            ->and(WaitlistConfig::authenticationRequired())->toBeTrue()
            ->and(WaitlistConfig::clientIpHeader())->toBe('X-Visitor-Ip')
            ->and([$export->spreadsheetSafe, $export->columns])->toBe([false, ['email', 'id']]);

        WaitlistConfig::check();
    });

    it('holds only what config:cache can write, and reads back from it as it went in', function () {
        $config = PackageConfig::merge([]);
        $types = [];

        array_walk_recursive($config, function (mixed $value) use (&$types): void {
            $types[get_debug_type($value)] = true;
        });

        expect(array_diff(array_keys($types), ['string', 'int', 'bool', 'null']))->toBe([])
            ->and(eval('return '.var_export($config, true).';'))->toBe($config);
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
