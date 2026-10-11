<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Facade;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\WaitlistServiceProvider;

/**
 * Registers the routes as the provider does on boot, with the shared
 * middleware of an app.
 *
 * @param  list<string>  $shared  waitlist.routes.middleware
 * @param  array<string, mixed>  $config
 */
function registerRoutes(array $shared = ['api'], array $config = []): void
{
    // The skeleton's .env may name the database cache; a limiter needs a store.
    config()->set('cache.default', 'array');
    app()->forgetInstance(CacheRateLimiter::class);
    Facade::clearResolvedInstance(CacheRateLimiter::class);

    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, $shared);

    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    (new WaitlistServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

/**
 * The package routes as "METHOD uri", whatever their names.
 *
 * @return list<string>
 */
function packageRoutes(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_contains((string) $route->getActionName(), 'Taldres\\Waitlist\\Http\\Controllers'))
        ->map(fn (Route $route): string => $route->methods()[0].' '.$route->uri())
        ->sort()
        ->values()
        ->all();
}

/**
 * What php artisan route:cache writes and a cached boot reads back: the
 * provider then loads no routes file, the cache is all there is.
 */
function cacheRoutes(): void
{
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    app('router')->setCompiledRoutes(unserialize(serialize($routes->compile())));
}

/**
 * php artisan route:clear, then a boot that registers the routes again.
 */
function registerRoutesAgain(): void
{
    app('router')->setRoutes(new RouteCollection);

    (new WaitlistServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

describe('registering the routes', function () {
    it('registers none while the switch is off', function () {
        config()->set(ConfigKey::RoutesEnabled->value, false);

        (new WaitlistServiceProvider(app()))->boot();

        expect(packageRoutes())->toBe([]);
    });

    it('leaves one set of routes when the provider boots twice', function () {
        registerRoutes();
        $once = packageRoutes();

        (new WaitlistServiceProvider(app()))->boot();
        app('router')->getRoutes()->refreshNameLookups();

        expect($once)->not->toBeEmpty()
            ->and(packageRoutes())->toBe($once);
    });
});

describe('a route cache', function () {
    beforeEach(function () {
        Exceptions::fake();

        // Before the routes are on, which the confirm links would point at.
        $this->tokens = subscribeAndCapture('beta', 'user@example.com');
    });

    it('keeps a group left out after its setting is fixed, until the cache is built again', function () {
        registerRoutes(['api'], [ConfigKey::SignupLimiter->value => 5]);
        cacheRoutes();

        // The deploy fixed the config but did not build the cache again.
        config()->set(ConfigKey::SignupLimiter->value, 'waitlist');

        $this->getJson('/waitlist/purposes?list=beta')->assertNotFound();
        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")->assertOk();

        expect(Artisan::call('waitlist:check'))->toBe(1)
            ->and(Artisan::output())->toContain('The signup routes are not registered although routes.enabled is on');

        registerRoutesAgain();

        $this->getJson('/waitlist/purposes?list=beta')->assertOk();

        expect(Artisan::call('waitlist:check'))->toBe(0);
    });

    it('keeps every route out when a setting that places them did not read, until it is built again', function () {
        registerRoutes(['api'], [ConfigKey::RoutesPrefix->value => ['waitlist']]);
        cacheRoutes();

        config()->set(ConfigKey::RoutesPrefix->value, 'waitlist');

        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")->assertNotFound();

        expect(Artisan::call('waitlist:check'))->toBe(1)
            ->and(Artisan::output())->toContain('The signup routes are not registered', 'The links routes are not registered');

        registerRoutesAgain();

        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")->assertOk();
    });

    it('keeps serving routes that were cached while they were on, so routes.enabled is no switch for them', function () {
        registerRoutes();
        cacheRoutes();

        config()->set(ConfigKey::RoutesEnabled->value, false);

        $this->getJson('/waitlist/purposes?list=beta')->assertOk();

        expect(Artisan::call('waitlist:check'))->toBe(1)
            ->and(Artisan::output())->toContain('routes.enabled is off, but the signup routes are still registered');

        registerRoutesAgain();

        $this->getJson('/waitlist/purposes?list=beta')->assertNotFound();
        expect(Artisan::call('waitlist:check'))->toBe(0);
    });
});

describe('the web group on the signup routes', function () {
    beforeEach(function () {
        // As docs/securing-the-endpoints.md recommends. Laravel skips the CSRF
        // check while the app runs as "testing", so it runs as production.
        config()->set('session.driver', 'array');
        app()->instance('env', 'production');

        $this->mail = [];
        Event::listen(EntrySubscribed::class, function (EntrySubscribed $event): void {
            $this->mail = ['confirm' => $event->confirmToken, 'unsubscribe' => $event->unsubscribeToken];
        });
        Waitlist::project('default')->for('beta')->add('user@example.com', waitlistConsent());

        registerRoutes(['api'], [ConfigKey::SignupMiddleware->value => ['web']]);

        $this->signUp = ['email' => 'other@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()];
    });

    it('refuses a form post that carries no token', function () {
        $this->postJson('/waitlist', $this->signUp)->assertStatus(419);
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertStatus(419);

        expect(WaitlistEntry::query()->count())->toBe(1);
    });

    it('hands the form its token with the wording, and accepts it back on the signup', function () {
        $wording = $this->getJson('/waitlist/purposes?list=beta')->assertOk();
        $cookies = collect($wording->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());

        expect($cookies->keys()->all())->toContain('XSRF-TOKEN', config('session.cookie'));

        $this->withUnencryptedCookies([config('session.cookie') => $cookies[config('session.cookie')]->getValue()])
            ->withHeaders(['X-XSRF-TOKEN' => $cookies['XSRF-TOKEN']->getValue()])
            ->postJson('/waitlist', $this->signUp)
            ->assertStatus(202);

        $this->call('HEAD', '/waitlist/purposes?list=beta')->assertOk();

        expect(WaitlistEntry::query()->count())->toBe(2);
    });

    it('leaves the token links free of the token and the session, as a mail client sends neither', function () {
        $confirm = $this->postJson("/waitlist/confirm/{$this->mail['confirm']}")->assertOk();

        $manage = manageTokenFor(WaitlistEntry::query()->firstOrFail());

        $this->postJson("/waitlist/manage/{$manage}/data")->assertOk();
        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => waitlistConsent()])->assertOk();
        $this->post("/waitlist/unsubscribe/{$this->mail['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

        expect($confirm->headers->getCookies())->toBe([])
            ->and(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Unsubscribed);
    });
});
