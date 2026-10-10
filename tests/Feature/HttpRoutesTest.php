<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Tests\Fixtures\RequireFormCheck;

beforeEach(function () {
    // The provider registers routes at boot, before this flag is set, so register them again.
    // Middleware is cleared: the testbench skeleton defines no "api" group.
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';

    $this->tokens = function (): array {
        $tokens = [];
        Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$tokens) {
            $tokens = ['confirm' => $event->confirmToken, 'unsubscribe' => $event->unsubscribeToken];
        });

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()]);

        return $tokens;
    };
});

it('subscribes and answers identically for a repeat', function () {
    $first = $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()]);
    $second = $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()]);

    $first->assertStatus(202)->assertJson(['message' => 'Subscribed.']);

    expect($second->getStatusCode())->toBe($first->getStatusCode())
        ->and($second->getContent())->toBe($first->getContent())
        ->and(WaitlistEntry::query()->count())->toBe(1);
});

it('validates the email', function (string $email) {
    $this->postJson('/waitlist', ['email' => $email, 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(WaitlistEntry::query()->count())->toBe(0);
})->with([
    'not an address' => ['nope'],
    // Valid by RFC, but not storable: answered as a validation error, not a 500.
    'no domain' => ['user@localhost'],
    'unicode local part' => ['jörg@example.com'],
]);

it('accepts only plain list names', function (string $list) {
    $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => $list, 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('list');
})->with(['../../etc', '<b>beta</b>', 'beta list', str_repeat('a', 101)]);

it('accepts no metadata unless fields are allow-listed', function () {
    $this->postJson('/waitlist', [
        'email' => 'user@example.com',
        'purposes' => waitlistConsent(),
        'metadata' => ['source' => 'landing'],
    ])->assertStatus(422)->assertJsonValidationErrors('metadata');

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('accepts allow-listed metadata fields under their rules only', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string', 'max:20']]));

    $post = fn (array $metadata) => $this->postJson('/waitlist', [
        'email' => 'user@example.com',
        'purposes' => waitlistConsent(),
        'metadata' => $metadata,
    ]);

    $post(['source' => 'landing', 'name' => 'Jane'])->assertStatus(422)->assertJsonValidationErrors('metadata');
    $post(['source' => str_repeat('a', 21)])->assertStatus(422)->assertJsonValidationErrors('metadata.source');
    $post(['source' => 'landing'])->assertStatus(202);

    expect(WaitlistEntry::query()->firstOrFail()->metadata)->toBe(['source' => 'landing']);
});

it('answers invalid UTF-8 in metadata the same for new and known addresses', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string']]));

    $post = fn (string $email) => $this->call('POST', '/waitlist', ['email' => $email, 'purposes' => waitlistConsent(), 'metadata' => ['source' => "bad\xFFbyte"]]);

    $post('known@example.com')->assertStatus(202);
    $post('known@example.com')->assertStatus(202);
    $post('new@example.com')->assertStatus(202);
});

it('keeps the copy of the data readable after a malformed user agent', function () {
    config()->set(ConfigKey::StoreUserAgent->value, true);

    $this->withHeaders(['User-Agent' => "Agent\xE9"])->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()]);

    $manage = manageTokenFor(WaitlistEntry::query()->firstOrFail());

    $this->postJson("/waitlist/manage/{$manage}/data")->assertOk();
});

it('names the allow-listed metadata fields when others are sent', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string'], 'campaign' => ['nullable', 'string']]));

    $this->postJson('/waitlist', [
        'email' => 'user@example.com',
        'purposes' => waitlistConsent(),
        'metadata' => ['source' => 'landing', 'utm' => 'x'],
    ])->assertStatus(422)->assertJsonPath('errors.metadata.0', 'The metadata field must be an array with only these keys: source, campaign.');
});

it('names the field when the list or a purpose is not available', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->optional('newsletter'), lists: false);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'gamma', 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('list');
    $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => [...waitlistConsent(), 'admin' => '1']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('purposes');

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('stores the server wording for the versions posted and never client text', function () {
    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => ['waitlist' => '2025-01']])->assertStatus(422);
    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => ['waitlist' => ['version' => '2026-11', 'text' => 'free']]])
        ->assertForbidden()
        ->assertJsonPath('message', "Only the project's own servers may send wording; send the version that was shown.");

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent('2026-09')])->assertStatus(202);

    expect(WaitlistEntry::query()->firstOrFail()->latestSubscription->consents[0]->text)->toBe('Earlier wording.');
});

it('refuses a subscription without the primary purpose', function () {
    $this->postJson('/waitlist', ['email' => 'user@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('purposes');

    $response = $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => ['newsletter' => '2026-10']]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('purposes')
        ->assertJsonPath('errors.purposes.0', 'Consent to the purpose [waitlist] is required.');
    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('answers validation errors as JSON for every client, without a stack trace', function () {
    config()->set('app.debug', true);

    $this->post('/waitlist', ['email' => 'user@example.com', 'purposes' => ['newsletter' => '2026-10']])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'Consent to the purpose [waitlist] is required.',
            'errors' => ['purposes' => ['Consent to the purpose [waitlist] is required.']],
        ]);
    $this->post('/waitlist', ['email' => 'nope', 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('reports confirm state on GET and confirms on POST', function () {
    $tokens = ($this->tokens)();

    $this->getJson("/waitlist/confirm/{$tokens['confirm']}")
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Pending->value)
        ->assertJsonMissingPath('data.unsubscribe_token')
        ->assertJsonMissingPath('data.unsubscribe_token_hash');

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);

    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Confirmed->value);
});

it('keeps the data wrapper when the app turns wrapping off', function () {
    $tokens = ($this->tokens)();
    JsonResource::withoutWrapping();

    try {
        $this->postJson("/waitlist/confirm/{$tokens['confirm']}")
            ->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Confirmed->value);
    } finally {
        JsonResource::wrap('data');
    }
});

it('answers 404 and 410 for bad confirm tokens on both methods, naming the error', function () {
    $tokens = ($this->tokens)();

    $this->getJson('/waitlist/confirm/unknown')
        ->assertStatus(404)
        ->assertExactJson(['message' => 'Invalid token.', 'error' => 'invalid_token']);
    $this->postJson('/waitlist/confirm/unknown')->assertStatus(404)->assertJsonPath('error', 'invalid_token');

    $this->travel(8)->days();

    $this->getJson("/waitlist/confirm/{$tokens['confirm']}")
        ->assertStatus(410)
        ->assertExactJson(['message' => 'This confirmation link has expired.', 'error' => 'expired_token']);
    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertStatus(410)->assertJsonPath('error', 'expired_token');
});

it('names no error on a 404 that did not come from a package route', function () {
    $this->postJson('/waitlist/nope/unknown')->assertNotFound()->assertJsonMissingPath('error');
});

it('adds middleware to the signup group only, so token links skip checks meant for forms', function (array|string $middleware) {
    $tokens = ($this->tokens)();
    config()->set(ConfigKey::SignupMiddleware->value, $middleware);

    require __DIR__.'/../../routes/waitlist.php';

    $signUp = ['email' => 'other@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()];

    $this->postJson('/waitlist', $signUp)->assertStatus(419);
    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk();
    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->withHeaders(['X-Form-Check' => 'passed'])->postJson('/waitlist', $signUp)->assertStatus(202);
})->with([
    'a list' => [[RequireFormCheck::class]],
    'a single one' => [RequireFormCheck::class],
]);

it('adds middleware to the token links only', function () {
    $tokens = ($this->tokens)();
    config()->set(ConfigKey::LinksMiddleware->value, [RequireFormCheck::class]);

    require __DIR__.'/../../routes/waitlist.php';

    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertStatus(419);
    $this->getJson('/waitlist/purposes?list=beta')->assertOk();
});

it('never unsubscribes on GET, only on POST', function () {
    $tokens = ($this->tokens)();

    $this->getJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Pending->value);

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);

    $this->getJson('/waitlist/unsubscribe/unknown')->assertStatus(404)->assertJsonPath('error', 'invalid_token');
    $this->postJson('/waitlist/unsubscribe/unknown')->assertStatus(404)->assertJsonPath('error', 'invalid_token');

    $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Unsubscribed->value);
});

it('never confirms or unsubscribes on HEAD, which link scanners probe with', function () {
    $tokens = ($this->tokens)();

    $this->call('HEAD', "/waitlist/confirm/{$tokens['confirm']}")->assertOk();
    $this->call('HEAD', "/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertOk();

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);
});

it('refuses a malformed purpose instead of leaving the whole list', function (string $query) {
    $tokens = ($this->tokens)();

    $this->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}?{$query}")->assertStatus(422);

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);
})->with(['purpose[]=newsletter', 'purpose=']);

it('accepts an rfc 8058 one-click request', function () {
    $tokens = ($this->tokens)();

    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Unsubscribed);
});

it('withdraws only the purpose a one-click link was sent for', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($tokens['confirm']);

    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    $entry = WaitlistEntry::query()->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->purposes)->toBe(['waitlist']);
});

it('passes the purpose on to the frontend page', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(unsubscribe: 'https://app.test/leave/{token}'));

    $tokens = ($this->tokens)();

    $this->get("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter")
        ->assertRedirect("https://app.test/leave/{$tokens['unsubscribe']}?purpose=newsletter");
});

it('never redirects a one-click request, as RFC 8058 requires', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(unsubscribed: 'https://app.test/goodbye'));

    $tokens = ($this->tokens)();

    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter", ['List-Unsubscribe' => 'One-Click'])
        ->assertOk();
    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Unsubscribed->value);

    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertRedirect('https://app.test/goodbye');
});

it('keeps the purpose ahead of a fragment that carries the token', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(unsubscribe: 'https://app.test/leave#{token}'));

    $tokens = ($this->tokens)();
    $entry = WaitlistEntry::query()->firstOrFail();

    $this->get("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter")
        ->assertRedirect("https://app.test/leave?purpose=newsletter#{$tokens['unsubscribe']}");

    expect(Waitlist::unsubscribeUrl($entry, 'newsletter'))->toBe("https://app.test/leave?purpose=newsletter#{$tokens['unsubscribe']}");
});

it('roots mail links at APP_URL, whatever host the signup came in on', function () {
    config()->set('app.url', 'https://waitlist.example.com');

    $urls = [];
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$urls) {
        $urls = [$event->confirmUrl, $event->unsubscribeUrl];
    });

    $this->postJson('http://evil.example/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])->assertStatus(202);

    $entry = WaitlistEntry::query()->firstOrFail();

    expect($urls)->each->toStartWith('https://waitlist.example.com/waitlist/')
        ->and(Waitlist::listUnsubscribeHeaders($entry)['List-Unsubscribe'])->toStartWith('<https://waitlist.example.com/waitlist/unsubscribe/');
});

it('exposes one-click headers pointing at the package endpoint', function () {
    $entry = WaitlistEntry::factory()->pending()->create();

    $headers = Waitlist::listUnsubscribeHeaders($entry);

    expect($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($headers['List-Unsubscribe'])->toContain('/waitlist/unsubscribe/');
});

it('redirects a browser to the frontend page with the token', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
        confirm: 'https://app.test/confirm/{token}',
        unsubscribe: 'https://app.test/leave/{token}',
    ));

    $tokens = ($this->tokens)();

    $this->get("/waitlist/confirm/{$tokens['confirm']}")->assertRedirect("https://app.test/confirm/{$tokens['confirm']}");
    $this->get("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertRedirect("https://app.test/leave/{$tokens['unsubscribe']}");

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Pending);
});

it('redirects the outcomes of a post', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
        confirmed: 'https://app.test/thanks',
        invalid: 'https://app.test/oops',
        unsubscribed: 'https://app.test/goodbye',
    ));

    $tokens = ($this->tokens)();

    $this->post('/waitlist/confirm/unknown')->assertRedirect('https://app.test/oops');
    $this->post("/waitlist/confirm/{$tokens['confirm']}")->assertRedirect('https://app.test/thanks');
    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertRedirect('https://app.test/goodbye');
});

it('keeps answering json to clients that ask for it', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
        confirmed: 'https://app.test/thanks',
        invalid: 'https://app.test/oops',
    ));

    $tokens = ($this->tokens)();

    $this->getJson('/waitlist/confirm/unknown')->assertStatus(404);
    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")
        ->assertOk()
        ->assertJsonPath('data.status', EntryStatus::Confirmed->value);
});

it('builds confirm urls from the package routes', function () {
    $url = null;
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$url) {
        $url = $event->confirmUrl;
    });

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()]);

    expect($url)->toContain('/waitlist/confirm/');
});

it('serves the routes and builds the mail links under any prefix and name', function (string $prefix, string $name, string $base) {
    config()->set(ConfigKey::RoutesPrefix->value, $prefix);
    config()->set(ConfigKey::RoutesName->value, $name);

    // As on a boot with this config, without the routes the setup above registered.
    app('router')->setRoutes(new RouteCollection);
    app('url')->setRoutes(app('router')->getRoutes());

    require __DIR__.'/../../routes/waitlist.php';
    app('router')->getRoutes()->refreshNameLookups();

    $mail = [];
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$mail) {
        $mail = [$event->confirmUrl, $event->confirmToken, $event->unsubscribeUrl, $event->unsubscribeToken];
    });

    $this->getJson("{$base}/purposes?list=beta")->assertOk();
    $this->postJson($base === '' ? '/' : $base, ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertStatus(202);

    [$confirmUrl, $confirm, $unsubscribeUrl, $unsubscribe] = $mail;
    $root = rtrim(config('app.url'), '/');

    expect($confirmUrl)->toBe("{$root}{$base}/confirm/{$confirm}")
        ->and($unsubscribeUrl)->toBe("{$root}{$base}/unsubscribe/{$unsubscribe}")
        ->and(app(ConfirmationUrlGenerator::class)->manageUrl(WaitlistEntry::query()->firstOrFail(), 'token'))->toBe("{$root}{$base}/manage/token");

    $this->post("{$base}/unsubscribe/{$unsubscribe}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(WaitlistEntry::query()->firstOrFail()->status)->toBe(EntryStatus::Unsubscribed);
})->with([
    'the default' => ['waitlist', 'waitlist.', '/waitlist'],
    'the root' => ['', 'waitlist.', ''],
    'slashes around it' => ['/waitlist/', 'waitlist.', '/waitlist'],
    'nested' => ['api/v1/waitlist', 'waitlist.', '/api/v1/waitlist'],
    'no name prefix' => ['waitlist', '', '/waitlist'],
    'the root, no name prefix' => ['', '', ''],
    'a name of its own' => ['join', 'newsletter.', '/join'],
]);

it('answers an invalid one-click request without a redirect too', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(invalid: 'https://app.test/oops'));

    $this->post('/waitlist/unsubscribe/unknown', ['List-Unsubscribe' => 'One-Click'])->assertNotFound();
    $this->post('/waitlist/unsubscribe/unknown')->assertRedirect('https://app.test/oops');
});

/**
 * A request for every method each package route takes, or for every method it
 * does not take and OPTIONS, with tokens that belong to a real entry.
 *
 * @param  array{confirm: string, unsubscribe: string}  $tokens
 * @return list<array{string, string}>
 */
function packageRouteRequests(array $tokens, string $manage, bool $methodsTaken): array
{
    $requests = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'waitlist.')) {
            continue;
        }

        $uri = '/'.str_replace('{token}', match (true) {
            str_contains($route->uri(), '/manage/') => $manage,
            str_contains($route->uri(), '/confirm/') => $tokens['confirm'],
            default => $tokens['unsubscribe'],
        }, $route->uri());

        $methods = $methodsTaken
            ? $route->methods()
            : [...array_diff(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()), 'OPTIONS'];

        foreach ($methods as $method) {
            $requests[] = [$method, $uri];
        }
    }

    return $requests;
}

describe('routes that are switched off but still registered, as in a route cache', function () {
    beforeEach(function () {
        $this->tokens = ($this->tokens)();
        $this->manage = manageTokenFor(WaitlistEntry::query()->firstOrFail());

        config()->set(ConfigKey::RoutesEnabled->value, false);

        $this->stored = fn (): array => collect([WaitlistEntry::class, WaitlistSubscription::class, WaitlistConsent::class, WaitlistActivity::class])
            ->map(fn (string $model): array => $model::query()->get()->map->getRawOriginal()->all())
            ->all();
    });

    it('answers 404 for every method a route takes', function () {
        $before = ($this->stored)();
        $requests = packageRouteRequests($this->tokens, $this->manage, methodsTaken: true);

        foreach ($requests as [$method, $uri]) {
            $this->call($method, $uri)->assertNotFound();
        }

        expect($requests)->not->toBeEmpty()
            ->and(($this->stored)())->toBe($before);
    });

    // Laravel answers a method a route does not take, and OPTIONS, before any
    // middleware runs. That is accepted, as long as nothing acts on them.
    it('answers 405 or the allowed methods for the others, and acts on nothing', function () {
        $events = collect(glob(__DIR__.'/../../src/Events/*.php'))
            ->map(fn (string $file): string => 'Taldres\\Waitlist\\Events\\'.basename($file, '.php'))
            ->all();
        $before = ($this->stored)();
        $requests = packageRouteRequests($this->tokens, $this->manage, methodsTaken: false);
        Event::fake($events);

        foreach ($requests as [$method, $uri]) {
            expect($this->call($method, $uri)->getStatusCode())->toBe($method === 'OPTIONS' ? 200 : 405, "{$method} {$uri}");
        }

        foreach ($events as $event) {
            Event::assertNotDispatched($event);
        }

        expect($requests)->not->toBeEmpty()
            ->and(($this->stored)())->toBe($before)
            ->and(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Pending);
    });
});
