<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\AuthenticatedProjectResolver;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;
use Taldres\Waitlist\WaitlistServiceProvider;

/**
 * "project:acme" is acme's server, "user" a caller without a project.
 */
function authorizationTestCaller(?string $header): ?Authenticatable
{
    return match (true) {
        $header === 'user' => new GenericUser(['id' => 7]),
        is_string($header) && str_starts_with($header, 'project:') => new ProjectCaller(substr($header, 8)),
        default => null,
    };
}

/**
 * The three requests that act for a project, each for acme's list "beta".
 *
 * @return array<string, Closure(): TestResponse>
 */
function authorizationTestEndpoints(): array
{
    return [
        'signup' => fn () => test()->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']]),
        'wording' => fn () => test()->getJson('/waitlist/purposes?list=beta'),
        'manage link by address' => fn () => test()->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta']),
    ];
}

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';

    Auth::viaRequest('waitlist-first', fn (Request $request) => authorizationTestCaller($request->header('X-First')));
    Auth::viaRequest('waitlist-second', fn (Request $request) => authorizationTestCaller($request->header('X-Second')));
    config()->set('auth.guards.first', ['driver' => 'waitlist-first']);
    config()->set('auth.guards.second', ['driver' => 'waitlist-second']);
    config()->set(ConfigKey::AuthenticationGuards->value, ['first', 'second']);

    foreach (['acme', 'other'] as $project) {
        Waitlist::define($project, function (ProjectDefinition $definition): void {
            $definition->purpose('launch', ['v1' => 'Email me at launch.']);
            $definition->list('beta', purpose: 'launch');
        });
    }
});

describe('the default gate', function () {
    it('lets guests through while authentication is not required', function (Closure $request) {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('launch', ['v1' => 'Email me at launch.'])->list('beta', purpose: 'launch'));

        expect($request()->status())->toBeIn([200, 202]);
    })->with(authorizationTestEndpoints());

    it('keeps a caller with a project to that project', function () {
        config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);

        $signUp = fn (string $project) => $this->withHeaders(['X-First' => 'project:acme', 'X-Waitlist-Project' => $project])
            ->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']]);

        $signUp('other')->assertNotFound()->assertJsonMissingPath('error');
        $signUp('acme')->assertStatus(202);

        expect(WaitlistEntry::query()->pluck('project')->all())->toBe(['acme']);
    });

    it('lets a caller without a project through while authentication is not required', function () {
        $this->withHeader('X-First', 'user')
            ->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])
            ->assertStatus(202);
    });

    describe('with authentication required', function () {
        beforeEach(function () {
            config()->set(ConfigKey::AuthenticationRequired->value, true);
            config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);
            $this->withHeader('X-Waitlist-Project', 'acme');
        });

        it('refuses guests', function (Closure $request) {
            $request()->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');

            expect(WaitlistEntry::query()->count())->toBe(0);
        })->with(authorizationTestEndpoints());

        it('refuses a caller without a project', function (Closure $request) {
            $this->withHeader('X-First', 'user');

            $request()->assertForbidden()->assertJsonPath('message', 'This caller does not act for a waitlist project.');
        })->with(authorizationTestEndpoints());

        it('lets a project act for itself', function (Closure $request) {
            $this->withHeader('X-First', 'project:acme');

            expect($request()->status())->toBeIn([200, 202]);
        })->with(authorizationTestEndpoints());

        it('reads the switch the way env() leaves it', function () {
            config()->set(ConfigKey::AuthenticationRequired->value, 'off');

            $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']])->assertStatus(202);
        });
    });
});

describe('AuthenticatedProjectResolver', function () {
    beforeEach(fn () => config()->set(ConfigKey::ProjectResolver->value, AuthenticatedProjectResolver::class));

    it('takes the project from the caller', function () {
        Event::fake([ManageLinkRequested::class]);

        $this->withHeader('X-First', 'project:acme');

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']])->assertStatus(202);
        $this->getJson('/waitlist/purposes?list=beta')->assertOk()->assertJsonPath('data.0.text', 'Email me at launch.');

        expect(Waitlist::project('acme')->for('beta')->has('user@example.com'))->toBeTrue()
            ->and(Waitlist::project('other')->for('beta')->count())->toBe(0);
    });

    it('refuses a request it cannot tie to a project', function (?string $caller, int $status, string $message) {
        $this->withHeaders(array_filter(['X-First' => $caller]))
            ->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']])
            ->assertStatus($status)
            ->assertJsonPath('message', $message);

        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with([
        'guest' => [null, 401, 'Unauthenticated.'],
        'caller without a project' => ['user', 403, 'This caller does not act for a waitlist project.'],
        'project that is not defined' => ['project:ghost', 403, 'This project has no waitlist.'],
    ]);

    it('answers before validating, whatever the body', function () {
        $this->postJson('/waitlist', ['metadata' => 'not even an object'])->assertUnauthorized();
        $this->postJson('/waitlist/manage-link', [])->assertUnauthorized();
    });
});

describe('several guards', function () {
    it('takes the first guard that authenticates, for the resolver and the gate alike', function (array $headers, string $expected) {
        config()->set(ConfigKey::ProjectResolver->value, AuthenticatedProjectResolver::class);
        $seen = [];

        Gate::define(WaitlistGate::ABILITY, function (?Authenticatable $caller, string $project) use (&$seen): bool {
            $seen = [$caller instanceof ProjectCaller ? $caller->waitlistProject() : null, $project];

            return true;
        });

        $this->withHeaders($headers)
            ->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']])
            ->assertStatus(202);

        expect($seen)->toBe([$expected, $expected])
            ->and(WaitlistEntry::query()->sole()->project)->toBe($expected);
    })->with([
        'both, the first wins' => [['X-First' => 'project:acme', 'X-Second' => 'project:other'], 'acme'],
        'only the second' => [['X-Second' => 'project:other'], 'other'],
    ]);

    it('uses the default guard unless told otherwise', function () {
        config()->set(ConfigKey::AuthenticationGuards->value, [null]);
        config()->set('auth.defaults.guard', 'second');

        $this->withHeader('X-Second', 'project:other');

        Route::get('/caller', fn (Request $request) => ['project' => Waitlist::caller($request)?->getAuthIdentifier()]);

        $this->getJson('/caller')->assertExactJson(['project' => 'project:other']);
    });

    it('decides once per request', function () {
        Route::get('/caller', function (Request $request) {
            $first = Waitlist::caller($request)?->getAuthIdentifier();
            config()->set(ConfigKey::AuthenticationGuards->value, ['second']);

            return ['first' => $first, 'again' => Waitlist::caller($request)?->getAuthIdentifier()];
        });

        $this->withHeaders(['X-First' => 'project:acme', 'X-Second' => 'project:other'])
            ->getJson('/caller')
            ->assertExactJson(['first' => 'project:acme', 'again' => 'project:acme']);
    });

    it('names a guard the app does not define as a configuration error', function () {
        config()->set(ConfigKey::AuthenticationGuards->value, ['first', 'missing']);

        Waitlist::caller(request());
    })->throws(InvalidConfigurationException::class, 'The waitlist.authentication.guards config names a guard config/auth.php does not define: missing.');

    it('refuses guards that are not names', function () {
        config()->set(ConfigKey::AuthenticationGuards->value, 'first');

        Waitlist::caller(request());
    })->throws(InvalidArgumentException::class, 'The '.ConfigKey::AuthenticationGuards->value.' config must list guard names, or null for the default guard, got string.');
});

describe('a gate of your own', function () {
    it('answers with the status and the message it chose', function (Closure $request, Response $response, int $status, string $message) {
        config()->set(ConfigKey::ProjectResolver->value, AuthenticatedProjectResolver::class);
        Gate::define(WaitlistGate::ABILITY, fn (?Authenticatable $caller) => $response);

        $this->withHeader('X-First', 'project:acme');

        $request()->assertStatus($status)->assertJsonPath('message', $message);
    })->with(authorizationTestEndpoints())->with([
        '401' => [fn () => Response::denyWithStatus(401, 'Token missing.'), 401, 'Token missing.'],
        '404' => [fn () => Response::denyAsNotFound('Nothing here.'), 404, 'Nothing here.'],
        '403' => [fn () => Response::deny('Not for you.'), 403, 'Not for you.'],
    ]);

    it('wins whichever provider boots first', function (bool $packageFirst) {
        // A gate without any abilities, as before any provider booted.
        $this->app->instance(GateContract::class, new Illuminate\Auth\Access\Gate($this->app, fn () => null));
        Gate::clearResolvedInstance(GateContract::class);

        $own = fn (?Authenticatable $caller) => Response::deny('Ours.');
        $bootPackage = fn () => (new WaitlistServiceProvider($this->app))->boot();

        expect(Gate::has(WaitlistGate::ABILITY))->toBeFalse();

        if ($packageFirst) {
            $bootPackage();
            Gate::define(WaitlistGate::ABILITY, $own);
        } else {
            Gate::define(WaitlistGate::ABILITY, $own);
            $bootPackage();
        }

        expect(Gate::forUser(null)->inspect(WaitlistGate::ABILITY, ['default', WaitlistAction::Subscribe, 'beta'])->message())->toBe('Ours.');
    })->with([
        'package first' => [true],
        'app first' => [false],
    ]);

    it('falls back to the package default when the app defines none', function () {
        $this->app->instance(GateContract::class, new Illuminate\Auth\Access\Gate($this->app, fn () => null));
        Gate::clearResolvedInstance(GateContract::class);

        (new WaitlistServiceProvider($this->app))->boot();

        expect(Gate::forUser(new ProjectCaller('acme'))->inspect(WaitlistGate::ABILITY, ['other', WaitlistAction::Subscribe, 'beta'])->status())->toBe(404);
    });

    it('is asked with the action and the list the request acts on', function (Closure $request, WaitlistAction $action, string $list) {
        $asked = null;
        Gate::define(WaitlistGate::ABILITY, function (?Authenticatable $caller, string $project, WaitlistAction $action, ?string $list) use (&$asked): bool {
            $asked = [$project, $action, $list];

            return true;
        });

        $request();

        expect($asked)->toBe(['default', $action, $list]);
    })->with([
        'signup' => [fn () => test()->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()]), WaitlistAction::Subscribe, 'beta'],
        'signup without a list' => [fn () => test()->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()]), WaitlistAction::Subscribe, 'default'],
        'signup with a list that is no string' => [fn () => test()->postJson('/waitlist', ['email' => 'user@example.com', 'list' => ['beta'], 'purposes' => waitlistConsent()])->assertStatus(422), WaitlistAction::Subscribe, 'default'],
        'wording' => [fn () => test()->getJson('/waitlist/purposes?list=beta'), WaitlistAction::ViewPurposes, 'beta'],
        'wording without a list' => [fn () => test()->getJson('/waitlist/purposes'), WaitlistAction::ViewPurposes, 'default'],
        'manage link by address' => [fn () => test()->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta']), WaitlistAction::RequestManageLink, 'beta'],
        'manage link with a list that is no string' => [fn () => test()->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => ['beta']])->assertStatus(422), WaitlistAction::RequestManageLink, 'default'],
    ]);

    it('is asked before the body is validated, and after the resolver', function () {
        Gate::define(WaitlistGate::ABILITY, fn (?Authenticatable $caller) => Response::deny('No.'));

        $this->postJson('/waitlist', ['metadata' => 'not even an object'])->assertForbidden();
        $this->postJson('/waitlist/manage-link', ['email' => 'not an address'])->assertForbidden();

        config()->set(ConfigKey::ProjectResolver->value, AuthenticatedProjectResolver::class);

        $this->postJson('/waitlist', [])->assertUnauthorized();
    });

    it('is never asked for a token', function () {
        Event::fake([ManageLinkRequested::class]);
        Gate::define(WaitlistGate::ABILITY, fn (?Authenticatable $caller) => Response::deny('No.'));
        $tokens = subscribeAndCapture('beta', 'user@example.com');

        $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}/manage-link")->assertStatus(202);
        $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk();
        $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertOk();

        Event::assertDispatchedTimes(ManageLinkRequested::class, 1);
    });
});

it('tells which projects exist, the default one always among them', function () {
    expect(Waitlist::hasProject('acme'))->toBeTrue()
        ->and(Waitlist::hasProject('default'))->toBeTrue()
        ->and(Waitlist::hasProject('ghost'))->toBeFalse();
});
