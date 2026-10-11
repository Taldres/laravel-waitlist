<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
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
