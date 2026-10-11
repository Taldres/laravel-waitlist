<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\AuthenticatedProjectResolver;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;

/*
| The way to require a login on a central API: Laravel's own auth middleware on
| the signup group, and AuthenticatedProjectResolver for the project. The guard
| here reads a bearer token the way Sanctum does and stands in for it; no
| Sanctum code is involved, since the package has none.
*/
beforeEach(function () {
    Auth::viaRequest('waitlist-bearer', fn (Request $request) => match ($request->bearerToken()) {
        'acme-token' => new ProjectCaller('acme'),
        'other-token' => new ProjectCaller('other'),
        'ghost-token' => new ProjectCaller('ghost'),
        'user-token' => new GenericUser(['id' => 7]),
        default => null,
    });
    config()->set('auth.guards.bearer', ['driver' => 'waitlist-bearer']);

    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    config()->set(ConfigKey::SignupMiddleware->value, ['auth:bearer']);
    app()->bind(ProjectResolver::class, AuthenticatedProjectResolver::class);
    config()->set(ConfigKey::CallerSignupPerMinute->value, 2);

    require __DIR__.'/../../routes/waitlist.php';

    foreach (['acme', 'other'] as $project) {
        Waitlist::define($project, function (ProjectDefinition $definition): void {
            $definition->purpose('launch', ['v1' => 'Email me at launch.']);
            $definition->list('beta', purpose: 'launch');
        });
    }

    // A request of its own: the framework starts every request with the default
    // guard, and the one the previous request used would still hold its user.
    $this->asToken = function (?string $token) {
        Auth::forgetGuards();
        config()->set('auth.defaults.guard', 'web');
        $this->flushHeaders();

        return $token === null ? $this : $this->withToken($token);
    };

    $this->signUp = fn (?string $token, string $email = 'user@example.com') => ($this->asToken)($token)
        ->postJson('/waitlist', ['email' => $email, 'list' => 'beta', 'purposes' => ['launch' => 'v1']]);
});

it('refuses a request without a token on every endpoint of the signup group', function () {
    ($this->asToken)(null)->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']])
        ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    ($this->asToken)(null)->getJson('/waitlist/purposes?list=beta')->assertUnauthorized();
    ($this->asToken)(null)->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertUnauthorized();
    ($this->asToken)('wrong-token')->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['launch' => 'v1']])->assertUnauthorized();

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('signs up for the project the token belongs to', function () {
    ($this->signUp)('acme-token')->assertStatus(202);
    ($this->signUp)('other-token', 'second@example.com')->assertStatus(202);

    expect(Waitlist::project('acme')->for('beta')->has('user@example.com'))->toBeTrue()
        ->and(Waitlist::project('acme')->for('beta')->count())->toBe(1)
        ->and(Waitlist::project('other')->for('beta')->has('second@example.com'))->toBeTrue()
        ->and(Waitlist::project('other')->for('beta')->count())->toBe(1);
});

it('refuses a token that belongs to no project', function () {
    ($this->signUp)('user-token')->assertForbidden()->assertJsonPath('message', 'This caller does not act for a waitlist project.');

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('refuses a token whose project has no waitlist', function () {
    ($this->signUp)('ghost-token')->assertForbidden()->assertJsonPath('message', 'This project has no waitlist.');

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('limits each server by itself, as the login runs before the limiter', function () {
    ($this->signUp)('acme-token', 'one@example.com')->assertStatus(202);
    ($this->signUp)('acme-token', 'two@example.com')->assertStatus(202);
    ($this->signUp)('acme-token', 'three@example.com')->assertStatus(429);

    // Same address, other server: the limit is not shared.
    ($this->signUp)('other-token', 'four@example.com')->assertStatus(202);
});

it('leaves the links in the mails open without a token', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', ['launch' => 'v1'], project: 'acme');

    ($this->asToken)(null)->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk();
    ($this->asToken)(null)->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();
});
