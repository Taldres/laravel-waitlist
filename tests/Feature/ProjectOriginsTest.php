<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\OriginPolicy;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;
use Taldres\Waitlist\Tests\TestCase;

const ACME_ORIGIN = 'https://acme.example';

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);

    require __DIR__.'/../../routes/waitlist.php';

    Auth::viaRequest('waitlist-server', fn (Request $request) => is_string($project = $request->header('X-Server')) ? new ProjectCaller($project) : null);
    config()->set('auth.guards.server', ['driver' => 'waitlist-server']);
    config()->set(ConfigKey::AuthenticationGuards->value, ['server']);

    defineDefaultProject();
    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->origins([ACME_ORIGIN, 'https://www.acme.example'])));
    Waitlist::define('rocket', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->origins(['https://rocket.example'])));
    Waitlist::define('open', fn (ProjectDefinition $project) => TestCase::defineTestProject($project));
});

function originSignup(string $project, ?string $origin, string $email = 'user@example.com'): TestResponse
{
    // The request guard keeps its user from the previous request.
    Auth::forgetGuards();

    return test()->postJson('/waitlist', ['email' => $email, 'list' => 'beta', 'purposes' => waitlistConsent()], array_filter([
        'X-Waitlist-Project' => $project,
        'Origin' => $origin,
    ]));
}

describe('a definition', function () {
    it('keeps origins the way a browser sends them', function () {
        $project = (new ProjectDefinition('shop'))->origins(['HTTPS://Shop.Example', 'https://shop.example:443', 'http://localhost:3000', 'http://localhost:80'])->origins(['https://other.example']);

        expect($project->getOrigins())->toBe(['https://shop.example', 'http://localhost:3000', 'http://localhost', 'https://other.example']);
    });

    it('refuses what is no origin', function (mixed $origin) {
        expect(fn () => (new ProjectDefinition('shop'))->origins([$origin]))->toThrow(InvalidConfigurationException::class);
    })->with([
        'a path' => ['https://shop.example/signup'],
        'a trailing slash' => ['https://shop.example/'],
        'a wildcard' => ['https://*.shop.example'],
        'no scheme' => ['shop.example'],
        'another scheme' => ['ftp://shop.example'],
        'a query' => ['https://shop.example?x=1'],
        'not a text' => [42],
    ]);

    it('lists no origin for a project that names none', function () {
        expect(OriginPolicy::all())->toEqualCanonicalizing([ACME_ORIGIN, 'https://www.acme.example', 'https://rocket.example']);
    });
});

describe('a project with origins', function () {
    it('lets a listed website sign up, and one that is not listed not', function () {
        originSignup('acme', ACME_ORIGIN)->assertStatus(202);
        originSignup('acme', 'https://www.acme.example', 'second@example.com')->assertStatus(202);

        originSignup('acme', 'https://evil.example', 'third@example.com')
            ->assertForbidden()
            ->assertJsonPath('message', 'This website may not use the waitlist.');
        originSignup('acme', 'https://rocket.example', 'fourth@example.com')->assertForbidden();

        expect(WaitlistEntry::query()->pluck('project')->unique()->all())->toBe(['acme'])
            ->and(WaitlistEntry::query()->count())->toBe(2);
    });

    it('lets a request through that names no origin, and the API\'s own', function () {
        originSignup('acme', null)->assertStatus(202);
        originSignup('acme', 'http://localhost', 'second@example.com')->assertStatus(202);
    });

    it('refuses the origin "null" and one it cannot read', function (string $origin) {
        originSignup('acme', $origin)->assertForbidden();
    })->with(['null', 'https://acme.example/path', '*', 'acme.example']);

    it('compares the origin the way a browser sends it', function () {
        originSignup('acme', 'HTTPS://ACME.EXAMPLE:443')->assertStatus(202);
    });

    it('asks for the wording and a manage link by address from the same websites only', function () {
        $this->getJson('/waitlist/purposes?list=beta', ['X-Waitlist-Project' => 'acme', 'Origin' => ACME_ORIGIN])->assertOk();
        $this->getJson('/waitlist/purposes?list=beta', ['X-Waitlist-Project' => 'acme', 'Origin' => 'https://evil.example'])->assertForbidden();
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'], ['X-Waitlist-Project' => 'acme', 'Origin' => 'https://evil.example'])->assertForbidden();
    });

    it('leaves the links in mails to any website, as they name no project', function () {
        $tokens = [];
        Event::listen(EntrySubscribed::class, function ($event) use (&$tokens) {
            $tokens = ['confirm' => $event->confirmToken, 'unsubscribe' => $event->unsubscribeToken];
        });

        originSignup('acme', ACME_ORIGIN)->assertStatus(202);

        $this->postJson("/waitlist/confirm/{$tokens['confirm']}", [], ['Origin' => 'https://evil.example'])->assertOk();
        $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}/manage-link", [], ['Origin' => 'https://evil.example'])->assertStatus(202);
    });

    it('is not undone by a gate that lets everyone through', function () {
        Gate::define('useWaitlist', fn () => Response::allow());

        originSignup('acme', 'https://evil.example')->assertForbidden();
    });

    it('does not apply to a server that calls for the project', function () {
        Auth::forgetGuards();

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()], [
            'X-Waitlist-Project' => 'acme',
            'X-Server' => 'acme',
            'Origin' => 'https://evil.example',
        ])->assertStatus(202);
    });
});

it('lets any website use a project that names no origin', function () {
    originSignup('open', 'https://anything.example')->assertStatus(202);
    originSignup('default', 'https://anything.example', 'second@example.com')->assertStatus(202);
});

describe('CORS', function () {
    function preflight(string $origin, string $path = '/waitlist'): TestResponse
    {
        return test()->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-waitlist-challenge',
        ]);
    }

    it('answers a preflight request from any website a project lists, without a line in config/cors.php', function (string $origin) {
        preflight($origin)->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin);
    })->with([ACME_ORIGIN, 'https://www.acme.example', 'https://rocket.example']);

    it('gives a website that no project lists no CORS headers', function () {
        preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('lets a browser read the answer to a listed website, and how long to wait after a 429', function () {
        $response = originSignup('acme', ACME_ORIGIN);

        $response->assertStatus(202)
            ->assertHeader('Access-Control-Allow-Origin', ACME_ORIGIN)
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After');
    });

    it('answers a refused website without CORS headers, so the browser cannot read why', function () {
        originSignup('acme', 'https://evil.example')
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('covers the token routes too', function () {
        preflight(ACME_ORIGIN, '/waitlist/confirm/some-token')->assertHeader('Access-Control-Allow-Origin', ACME_ORIGIN);
    });

    it('puts the settings back after the request', function () {
        $before = config('cors');

        preflight(ACME_ORIGIN);
        originSignup('acme', ACME_ORIGIN);

        expect(config('cors'))->toBe($before);
    });

    it('does not let Laravel\'s default of every origin undo the projects\'', function () {
        expect(config('cors.allowed_origins'))->toBe(['*']);

        preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
        originSignup('acme', 'https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('keeps what an app that covers the routes itself allows, also everything', function () {
        config()->set('cors.paths', ['waitlist', 'waitlist/*']);
        config()->set('cors.allowed_origins', ['*']);

        preflight('https://anything.example')->assertHeader('Access-Control-Allow-Origin', '*');
        expect(config('cors.allowed_origins'))->toBe(['*']);

        config()->set('cors.allowed_origins', ['https://app.example']);

        preflight('https://app.example')->assertHeader('Access-Control-Allow-Origin', 'https://app.example');
        preflight(ACME_ORIGIN)->assertHeader('Access-Control-Allow-Origin', ACME_ORIGIN);
        preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('leaves a request without an Origin header and one for another path alone', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()], ['X-Waitlist-Project' => 'acme'])
            ->assertStatus(202)
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        preflight(ACME_ORIGIN, '/elsewhere')->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('does nothing while the routes are off', function () {
        config()->set(ConfigKey::RoutesEnabled->value, false);

        preflight(ACME_ORIGIN)->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('does nothing when no project names an origin', function () {
        Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project));
        Waitlist::define('rocket', fn (ProjectDefinition $project) => TestCase::defineTestProject($project));

        preflight(ACME_ORIGIN)->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});
