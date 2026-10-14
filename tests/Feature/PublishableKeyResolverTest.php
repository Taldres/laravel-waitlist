<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PublishableKeyResolver;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;
use Taldres\Waitlist\Tests\TestCase;

const ACME_KEY = 'pk_acme_0123456789';
const ROCKET_KEY = 'pk_rocket_0123456789';

/**
 * @param  array<string, string|null>  $headers
 */
function keySignup(array $headers, string $email = 'user@example.com'): TestResponse
{
    // The request guard keeps its user from the previous request.
    Auth::forgetGuards();

    return test()->postJson('/waitlist', ['email' => $email, 'list' => 'beta', 'purposes' => waitlistConsent()], array_filter($headers));
}

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    app()->bind(ProjectResolver::class, PublishableKeyResolver::class);

    require __DIR__.'/../../routes/waitlist.php';

    Auth::viaRequest('waitlist-server', fn (Request $request) => is_string($project = $request->header('X-Server')) ? new ProjectCaller($project) : null);
    config()->set('auth.guards.server', ['driver' => 'waitlist-server']);
    config()->set(ConfigKey::AuthenticationGuards->value, ['server']);

    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->publishableKey(ACME_KEY)->origins(['https://acme.example'])));
    Waitlist::define('rocket', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->publishableKey(ROCKET_KEY)));
    Waitlist::define('keyless', fn (ProjectDefinition $project) => TestCase::defineTestProject($project));
});

describe('a definition', function () {
    it('keeps the key, and leaves it unset for null or an empty value', function () {
        expect((new ProjectDefinition('shop'))->publishableKey('pk_shop')->getPublishableKey())->toBe('pk_shop')
            ->and((new ProjectDefinition('shop'))->publishableKey(null)->getPublishableKey())->toBeNull()
            ->and((new ProjectDefinition('shop'))->publishableKey('')->getPublishableKey())->toBeNull()
            ->and((new ProjectDefinition('shop'))->publishableKey('pk_shop')->publishableKey(null)->getPublishableKey())->toBe('pk_shop');
    });

    it('refuses a key no header can carry as it is', function (string $key) {
        expect(fn () => (new ProjectDefinition('shop'))->publishableKey($key))
            ->toThrow(InvalidConfigurationException::class, 'The publishable key of the project [shop] must be 1 to 255 visible ASCII characters without spaces.');
    })->with([
        'a space' => ['pk shop'],
        'a line break' => ["pk_shop\n"],
        'not ASCII' => ['pk_shöp'],
        'too long' => [str_repeat('k', 256)],
    ]);
});

describe('the resolver', function () {
    it('signs up for the project the key belongs to', function () {
        keySignup(['X-Waitlist-Key' => ACME_KEY])->assertStatus(202);
        keySignup(['X-Waitlist-Key' => ROCKET_KEY], 'second@example.com')->assertStatus(202);

        expect(WaitlistEntry::query()->orderBy('id')->pluck('project')->all())->toBe(['acme', 'rocket']);
    });

    it('picks the project for the wording and a manage link by address too', function () {
        $this->getJson('/waitlist/purposes?list=beta', ['X-Waitlist-Key' => ROCKET_KEY])->assertOk();
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'], ['X-Waitlist-Key' => ROCKET_KEY])->assertStatus(202);

        $this->getJson('/waitlist/purposes?list=beta')->assertForbidden();
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertForbidden();
    });

    it('refuses a missing or unknown key like a project without a waitlist, before validating', function (?string $key) {
        Auth::forgetGuards();

        $this->postJson('/waitlist', ['metadata' => 'not even an object'], array_filter(['X-Waitlist-Key' => $key]))
            ->assertForbidden()
            ->assertJsonPath('message', 'Unknown waitlist key.');

        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with([
        'no key' => [null],
        'an unknown key' => ['pk_unknown'],
        'a prefix of a key' => [substr(ACME_KEY, 0, -1)],
        'a key in another case' => [strtoupper(ACME_KEY)],
        'a project name' => ['acme'],
    ]);

    it('authenticates nobody: a required login still refuses a guest with a valid key', function () {
        config()->set(ConfigKey::AuthenticationRequired->value, true);

        keySignup(['X-Waitlist-Key' => ACME_KEY])->assertUnauthorized();
        keySignup(['X-Waitlist-Key' => ACME_KEY, 'X-Server' => 'acme'])->assertStatus(202);

        expect(WaitlistEntry::query()->count())->toBe(1);
    });

    it('keeps the website check: another website may not use a key it copied', function () {
        keySignup(['X-Waitlist-Key' => ACME_KEY, 'Origin' => 'https://evil.example'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This website may not use the waitlist.');
        keySignup(['X-Waitlist-Key' => ACME_KEY, 'Origin' => 'https://acme.example'])->assertStatus(202);
    });

    it('keeps a caller with a project to its own, whatever key it sends', function () {
        keySignup(['X-Waitlist-Key' => ROCKET_KEY, 'X-Server' => 'acme'])->assertNotFound();
        keySignup(['X-Server' => 'acme'])->assertForbidden();
        keySignup(['X-Waitlist-Key' => ACME_KEY, 'X-Server' => 'acme'])->assertStatus(202);

        expect(WaitlistEntry::query()->pluck('project')->all())->toBe(['acme']);
    });

    it('refuses a key that two projects share rather than pick one', function () {
        Waitlist::define('copy', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->publishableKey(ACME_KEY)));

        expect(fn () => app(PublishableKeyResolver::class)->resolve(Request::create('/waitlist', 'POST', server: ['HTTP_X_WAITLIST_KEY' => ACME_KEY])))
            ->toThrow(InvalidConfigurationException::class, 'The projects [acme] and [copy] have the same publishable key.');

        expect(app(PublishableKeyResolver::class)->resolve(Request::create('/waitlist', 'POST', server: ['HTTP_X_WAITLIST_KEY' => ROCKET_KEY])))->toBe('rocket');
    });

    it('keeps the other projects reachable while one definition is broken, and reports it', function (Closure $define, string $class) {
        Exceptions::fake();
        Waitlist::define('broken', $define);

        keySignup(['X-Waitlist-Key' => ROCKET_KEY])->assertStatus(202);
        keySignup(['X-Waitlist-Key' => 'pk_broken'], 'second@example.com')->assertForbidden()->assertJsonPath('message', 'Unknown waitlist key.');

        Exceptions::assertReported($class);
    })->with([
        'a configuration error' => [fn (ProjectDefinition $project) => $project->publishableKey('pk broken'), InvalidConfigurationException::class],
        'any other error' => [function (ProjectDefinition $project): void {
            $project->publishableKey('pk_broken');

            throw new RuntimeException('The database is down.');
        }, RuntimeException::class],
        'a type error' => [fn (ProjectDefinition $project) => $project->publishableKey(...[42]), TypeError::class],
    ]);

    it('keeps a caller with a project to its own for the wording and a manage link too', function () {
        $headers = ['X-Waitlist-Key' => ROCKET_KEY, 'X-Server' => 'acme'];

        $this->getJson('/waitlist/purposes?list=beta', $headers)->assertNotFound();
        Auth::forgetGuards();
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'], $headers)->assertNotFound();
    });

    it('refuses a project the catalog does not know, from a subclass that reads keys elsewhere', function () {
        app()->bind(ProjectResolver::class, fn () => new class(app(ProjectDefinitions::class), app(ProjectCatalog::class)) extends PublishableKeyResolver
        {
            protected function project(string $key): ?string
            {
                return $key === 'pk_ghost' ? 'ghost' : 'rocket';
            }
        });

        keySignup(['X-Waitlist-Key' => 'pk_ghost'])->assertForbidden()->assertJsonPath('message', 'Unknown waitlist key.');
        keySignup(['X-Waitlist-Key' => 'pk_any'])->assertStatus(202);
    });

    it('leaves the links in mails alone, as they carry no key', function () {
        ['confirm' => $confirm] = subscribeAndCapture('beta', 'user@example.com', project: 'acme');

        $this->postJson("/waitlist/confirm/{$confirm}")->assertOk();
    });
});
