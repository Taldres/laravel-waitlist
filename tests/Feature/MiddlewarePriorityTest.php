<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Http\Middleware\CheckRouteConfig;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Tests\Fixtures\RequireFormCheck;
use Taldres\Waitlist\WaitlistServiceProvider;

/**
 * Registers the routes as the provider does on boot, with the middleware of an
 * app: the kernel hands the router the groups, such as "api", and the
 * priority list that sorts a route's middleware.
 *
 * @param  list<string>  $shared  waitlist.routes.middleware
 * @param  array<string, mixed>  $config
 */
function priorityRegister(array $shared = ['api'], array $config = []): void
{
    // The skeleton's .env may name the database cache; a limiter needs a store.
    config()->set('cache.default', 'array');
    app()->forgetInstance(CacheRateLimiter::class);
    Facade::clearResolvedInstance(CacheRateLimiter::class);

    app(Kernel::class);

    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, $shared);

    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    (new WaitlistServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

/**
 * The middleware of a route in the order the router runs them, by short name.
 *
 * @return list<string>
 */
function priorityOrderOf(string $route): array
{
    $router = app('router');

    return array_map(
        fn (string $middleware): string => class_basename($middleware),
        $router->gatherRouteMiddleware($router->getRoutes()->getByName($route)),
    );
}

/**
 * The package routes as "METHOD uri", whatever their names.
 *
 * @return list<string>
 */
function priorityPackageRoutes(): array
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

        expect(priorityPackageRoutes())->toBe([]);
    });

    it('leaves one set of routes when the provider boots twice', function () {
        priorityRegister();
        $once = priorityPackageRoutes();

        (new WaitlistServiceProvider(app()))->boot();
        app('router')->getRoutes()->refreshNameLookups();

        expect($once)->not->toBeEmpty()
            ->and(priorityPackageRoutes())->toBe($once);
    });
});

describe('the middleware priority list', function () {
    it('starts with the check once the app has booted', function () {
        $priority = app(Kernel::class)->getMiddlewarePriority();

        expect($priority[0])->toBe(CheckRouteConfig::class)
            ->and($priority)->toContain(ThrottleRequests::class, SubstituteBindings::class)
            ->and(app('router')->middlewarePriority)->toBe($priority);
    });

    it('lists the check once, however often the provider boots', function () {
        (new WaitlistServiceProvider(app()))->boot();
        (new WaitlistServiceProvider(app()))->boot();

        expect(array_keys(app(Kernel::class)->getMiddlewarePriority(), CheckRouteConfig::class, true))->toBe([0]);
    });

    it('goes in front of a list the app set before the package booted, and keeps that list', function () {
        // What priority() in bootstrap/app.php does when the kernel resolves,
        // which is before any provider boots.
        app(Kernel::class)->setMiddlewarePriority([ThrottleRequests::class, SubstituteBindings::class]);

        (new WaitlistServiceProvider(app()))->boot();

        expect(app(Kernel::class)->getMiddlewarePriority())->toBe([CheckRouteConfig::class, ThrottleRequests::class, SubstituteBindings::class]);
    });

    it('is left alone by a kernel that cannot prepend to it', function () {
        $kernel = new class(app()) implements Kernel
        {
            public function __construct(private readonly Application $app) {}

            public function bootstrap(): void {}

            public function handle($request): never
            {
                throw new LogicException('Not used.');
            }

            public function terminate($request, $response): void {}

            public function getApplication(): Application
            {
                return $this->app;
            }
        };
        app()->instance(Kernel::class, $kernel);

        (new WaitlistServiceProvider(app()))->boot();

        expect(app(Kernel::class))->toBe($kernel);
    });
});

describe('the order a route runs its middleware in', function () {
    it('runs the check ahead of everything Laravel sorts', function (array $shared, string $first) {
        priorityRegister($shared);

        expect(priorityOrderOf('waitlist.subscribe')[0])->toBe('CheckRouteConfig:signup')
            ->and(priorityOrderOf('waitlist.subscribe'))->toContain('ThrottleRequests:waitlist', $first)
            ->and(priorityOrderOf('waitlist.confirm')[0])->toBe('CheckRouteConfig:links')
            ->and(priorityOrderOf('waitlist.unsubscribe.manage-link')[0])->toBe('CheckRouteConfig:links');
    })->with([
        'api' => [['api'], 'SubstituteBindings'],
        'web' => [['web'], 'StartSession'],
    ]);

    it('keeps the middleware of a group behind the check and the limiter', function () {
        priorityRegister(['api'], [ConfigKey::SignupMiddleware->value => [RequireFormCheck::class]]);

        expect(priorityOrderOf('waitlist.subscribe'))->toBe(['CheckRouteConfig:signup', 'ThrottleRequests:waitlist', 'SubstituteBindings', 'RequireFormCheck']);
    });

    it('runs the check first again once an app that replaced the list names it', function () {
        priorityRegister(['api']);

        // What an app's own provider does after this one has booted.
        app(Kernel::class)->setMiddlewarePriority([CheckRouteConfig::class, ThrottleRequests::class, SubstituteBindings::class]);

        expect(priorityOrderOf('waitlist.subscribe'))->toBe(['CheckRouteConfig:signup', 'ThrottleRequests:waitlist', 'SubstituteBindings']);
    });

    it('leaves a middleware the list does not know where the app listed it', function () {
        priorityRegister([RequireFormCheck::class, 'api']);

        expect(priorityOrderOf('waitlist.subscribe'))->toBe(['RequireFormCheck', 'CheckRouteConfig:signup', 'ThrottleRequests:waitlist', 'SubstituteBindings']);
    });
});

describe('a route that is off', function () {
    it('answers 404 however often it is called, without counting against its limiter', function (array $shared) {
        priorityRegister($shared, [ConfigKey::SignupPerMinute->value => 1, ConfigKey::LinkPerMinute->value => 1]);
        config()->set(ConfigKey::RoutesEnabled->value, false);

        foreach (range(1, 3) as $ignored) {
            $this->getJson('/waitlist/purposes')->assertNotFound();
            $this->postJson('/waitlist/unsubscribe/some-token')->assertNotFound();
        }
    })->with([
        'with api' => [['api']],
        'with web' => [['web']],
        'with nothing the router sorts' => [[]],
    ]);

    it('leaves the limiter untouched for the day the routes go on', function () {
        priorityRegister(['api'], [ConfigKey::SignupPerMinute->value => 1]);
        config()->set(ConfigKey::RoutesEnabled->value, false);

        foreach (range(1, 3) as $ignored) {
            $this->getJson('/waitlist/purposes')->assertNotFound();
        }

        config()->set(ConfigKey::RoutesEnabled->value, true);

        $this->getJson('/waitlist/purposes?list=beta')->assertOk();
        $this->getJson('/waitlist/purposes?list=beta')->assertStatus(429);
    });

    it('answers 404 even when a rate limit does not read', function (ConfigKey $key, string $url, array $shared) {
        priorityRegister($shared);
        config()->set(ConfigKey::RoutesEnabled->value, false);
        config()->set($key->value, 'many');

        $this->getJson($url)->assertNotFound();
    })->with([
        'the signup limit' => [ConfigKey::SignupPerMinute, '/waitlist/purposes', ['api']],
        'the limit per token' => [ConfigKey::LinkPerMinute, '/waitlist/confirm/some-token', ['api']],
        'the limit per address' => [ConfigKey::LinksPerIpPerMinute, '/waitlist/confirm/some-token', ['api']],
        'the signup limit, with nothing the router sorts' => [ConfigKey::SignupPerMinute, '/waitlist/purposes', []],
    ]);

    it('starts no session and sets no cookie', function () {
        config()->set('session.driver', 'array');
        priorityRegister(['api'], [ConfigKey::SignupMiddleware->value => ['web']]);
        config()->set(ConfigKey::RoutesEnabled->value, false);

        $response = $this->getJson('/waitlist/purposes')->assertNotFound();

        expect($response->headers->getCookies())->toBe([]);
    });

    it('sets no cookie when the shared middleware is web either', function () {
        config()->set('session.driver', 'array');
        priorityRegister(['web']);
        config()->set(ConfigKey::RoutesEnabled->value, false);

        $response = $this->getJson('/waitlist/purposes')->assertNotFound();

        expect($response->headers->getCookies())->toBe([]);
    });

    it('does not run the middleware of its group', function () {
        priorityRegister(['api'], [ConfigKey::SignupMiddleware->value => [RequireFormCheck::class]]);
        config()->set(ConfigKey::RoutesEnabled->value, false);

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertNotFound();
    });
});

describe('a limiter that no limiter answers to', function () {
    it('names the config key with the shared middleware of a default app', function (ConfigKey $key, string $url) {
        priorityRegister(['api'], [$key->value => 'not-defined']);

        $this->withoutExceptionHandling();

        expect(fn () => $this->getJson($url))->toThrow(InvalidConfigurationException::class, $key->value);
    })->with([
        'signup' => [ConfigKey::SignupLimiter, '/waitlist/purposes?list=beta'],
        'links' => [ConfigKey::LinksLimiter, '/waitlist/confirm/some-token'],
    ]);
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

        priorityRegister(['api'], [ConfigKey::SignupMiddleware->value => ['web']]);

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
