<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\WaitlistServiceProvider;

/*
 * Leaving, withdrawing, confirming an issued link, erasing and pruning must
 * work whatever a setting they do not need says. Each flow is arranged with a
 * readable config, then the setting is broken and the flow runs.
 */

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';
});

/**
 * @return array{entry: WaitlistEntry, confirm: string|null, unsubscribe: string}
 */
function isolationSubscribe(bool $confirm = true, string $email = 'user@example.com'): array
{
    $tokens = subscribeAndCapture('beta', $email, [...waitlistConsent(), 'newsletter' => '2026-10']);

    if ($confirm) {
        Waitlist::confirm($tokens['confirm']);
    }

    return $tokens;
}

function isolationEntry(): WaitlistEntry
{
    return WaitlistEntry::query()->firstOrFail();
}

/**
 * Each flow arranges what it needs and returns the step that must still work.
 *
 * @return array<string, Closure(mixed): Closure(): void>
 */
function isolationFlows(): array
{
    return [
        'unsubscribe by token' => function ($test): Closure {
            $tokens = isolationSubscribe();

            return function () use ($tokens): void {
                Waitlist::unsubscribe($tokens['unsubscribe']);

                expect(isolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'withdraw one purpose' => function ($test): Closure {
            $tokens = isolationSubscribe();

            return function () use ($tokens): void {
                Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

                expect(WaitlistConsent::query()->where('purpose', 'newsletter')->whereNotNull('withdrawn_at')->exists())->toBeTrue();
            };
        },
        'one-click unsubscribe' => function ($test): Closure {
            $tokens = isolationSubscribe();

            return function () use ($test, $tokens): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                    ->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])
                    ->assertOk();

                expect(isolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'leave from the manage page' => function ($test): Closure {
            isolationSubscribe();
            $manage = Waitlist::manageLink(isolationEntry())->token;

            return function () use ($test, $manage): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson("/waitlist/manage/{$manage}/unsubscribe")->assertOk();

                expect(isolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'update purposes on the manage page' => function ($test): Closure {
            isolationSubscribe();
            $manage = Waitlist::manageLink(isolationEntry())->token;

            return function () use ($test, $manage): void {
                $test->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => waitlistConsent()])->assertOk();

                expect(WaitlistConsent::query()->where('purpose', 'newsletter')->whereNotNull('withdrawn_at')->exists())->toBeTrue();
            };
        },
        'erase from the manage page' => function ($test): Closure {
            isolationSubscribe();
            $manage = Waitlist::manageLink(isolationEntry())->token;

            return function () use ($test, $manage): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson("/waitlist/manage/{$manage}/erase", ['confirm' => true])->assertOk();

                expect(WaitlistEntry::query()->count())->toBe(0);
            };
        },
        'confirm an issued link' => function ($test): Closure {
            $tokens = isolationSubscribe(confirm: false);

            return function () use ($tokens): void {
                Waitlist::confirm($tokens['confirm']);

                expect(isolationEntry()->status)->toBe(EntryStatus::Confirmed);
            };
        },
        'confirm an issued link over HTTP' => function ($test): Closure {
            $tokens = isolationSubscribe(confirm: false);

            return function () use ($test, $tokens): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk();

                expect(isolationEntry()->status)->toBe(EntryStatus::Confirmed);
            };
        },
        'waitlist:forget a whole list' => function ($test): Closure {
            isolationSubscribe();

            return function (): void {
                expect(Artisan::call('waitlist:forget', ['--all' => true, '--list' => 'beta', '--force' => true]))->toBe(0)
                    ->and(WaitlistEntry::query()->count())->toBe(0);
            };
        },
        'waitlist:prune an abandoned signup' => function ($test): Closure {
            isolationSubscribe(confirm: false);
            $test->travel(31)->days();

            return function (): void {
                expect(Artisan::call('waitlist:prune'))->toBe(0)
                    ->and(WaitlistEntry::query()->count())->toBe(0);
            };
        },
    ];
}

/**
 * @param  array<string, mixed>  $settings
 */
function isolationRun(string $flow, array $settings, mixed $test): void
{
    $act = isolationFlows()[$flow]($test);

    foreach ($settings as $path => $value) {
        config()->set($path, $value);
    }

    $act();
}

/**
 * @param  list<string>  $flows
 * @param  array<string, array{0: string, 1: mixed}>  $settings
 * @return Generator<string, array{0: string, 1: string, 2: mixed}>
 */
function isolationCases(array $flows, array $settings): Generator
{
    foreach ($flows as $flow) {
        foreach ($settings as $label => [$setting, $value]) {
            yield "{$flow} / {$label}" => [$flow, $setting, $value];
        }
    }
}

describe('privacy settings that do not read', function () {
    $settings = [
        'store_ip is not a switch' => [ConfigKey::StoreIp->value, 'maybe'],
        'store_user_agent is not a switch' => [ConfigKey::StoreUserAgent->value, ['yes']],
        'the group is not settings' => ['waitlist.privacy', 'on'],
    ];

    it('keep no flow from working, and store neither the IP address nor the user agent', function (string $flow, string $setting, mixed $value) {
        $this->withoutExceptionHandling();

        isolationRun($flow, [$setting => $value], $this);

        expect(WaitlistActivity::query()->whereNotNull('ip')->orWhereNotNull('user_agent')->count())->toBe(0);
    })->with(isolationCases([
        'unsubscribe by token',
        'one-click unsubscribe',
        'leave from the manage page',
        'confirm an issued link',
        'confirm an issued link over HTTP',
        'waitlist:prune an abandoned signup',
    ], $settings));

    it('are reported once a step had something to store', function () {
        Exceptions::fake();

        isolationRun('one-click unsubscribe', [ConfigKey::StoreIp->value => 'maybe'], $this);

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => str_contains($exception->getMessage(), ConfigKey::StoreIp->value));
    });
});

describe('a setting a step falls back from', function () {
    it('is reported once per request, job or command, however often it is met', function () {
        Exceptions::fake();
        $unreadable = fn (): never => throw new InvalidConfigurationException('The waitlist.example config does not read.');

        foreach (range(1, 3) as $row) {
            expect(ConfigFallback::read($unreadable, fallback: 'fallback'))->toBe('fallback');
        }

        ConfigFallback::read(fn (): never => throw new InvalidConfigurationException('The waitlist.other config does not read.'), fallback: null);

        Exceptions::assertReportedCount(2);

        app()->forgetScopedInstances();
        ConfigFallback::read($unreadable, fallback: null);

        Exceptions::assertReportedCount(3);
    });

    it('lets any other exception through', function () {
        expect(fn () => ConfigFallback::read(fn (): never => throw new RuntimeException('Not a setting.'), fallback: null))
            ->toThrow(RuntimeException::class, 'Not a setting.');
    });
});

describe('a URL generator or catalog that does not resolve', function () {
    it('keeps no flow that builds no link from working', function (string $flow, string $setting, mixed $value) {
        $this->withoutExceptionHandling();

        isolationRun($flow, [$setting => $value], $this);
    })->with(function () {
        yield from isolationCases(['unsubscribe by token', 'withdraw one purpose', 'one-click unsubscribe', 'leave from the manage page', 'waitlist:prune an abandoned signup'], [
            'url_generator' => [ConfigKey::UrlGenerator->value, stdClass::class],
            'catalog' => [ConfigKey::Catalog->value, stdClass::class],
        ]);

        // Saving purposes reads the wording from the catalog, but no link.
        yield 'update purposes on the manage page / url_generator' => ['update purposes on the manage page', ConfigKey::UrlGenerator->value, stdClass::class];
    });
});

describe('a link that cannot be built', function () {
    $settings = [
        'routes.enabled is not a switch' => [ConfigKey::RoutesEnabled->value, 'maybe', InvalidConfigurationException::class],
        'the routes it names are not registered' => [ConfigKey::RoutesName->value, 'elsewhere.', InvalidConfigurationException::class],
    ];

    it('undoes the confirmation instead of leaving it unannounced', function (string $setting, mixed $value, string $exception) {
        $tokens = isolationSubscribe(confirm: false);
        Event::fake([EntryConfirmed::class]);

        config()->set($setting, $value);

        expect(fn () => Waitlist::confirm($tokens['confirm']))->toThrow($exception);
        expect(isolationEntry()->status)->toBe(EntryStatus::Pending)
            ->and(activityTypes())->not->toContain(ActivityType::Confirmed);
        Event::assertNotDispatched(EntryConfirmed::class);

        config()->set($setting, config()->get($setting) === 'maybe' ? true : 'waitlist.');

        Waitlist::confirm($tokens['confirm']);

        expect(isolationEntry()->status)->toBe(EntryStatus::Confirmed);
        Event::assertDispatchedTimes(EntryConfirmed::class, 1);
    })->with($settings);

    it('undoes the signup instead of leaving it without its mail', function (string $setting, mixed $value, string $exception) {
        Event::fake([EntrySubscribed::class]);

        config()->set($setting, $value);

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))->toThrow($exception);
        expect(WaitlistEntry::query()->count())->toBe(0);
        Event::assertNotDispatched(EntrySubscribed::class);
    })->with($settings);
});

describe('guards that do not resolve', function () {
    beforeEach(function () {
        $this->guards = [ConfigKey::AuthenticationGuards->value => ['no-such-guard']];
    });

    it('keep no link with a token from working', function (string $flow) {
        $this->withoutExceptionHandling();

        isolationRun($flow, $this->guards, $this);
    })->with(['one-click unsubscribe', 'leave from the manage page', 'erase from the manage page', 'confirm an issued link over HTTP']);

    it('keep the manage link by token from being mailed', function () {
        $this->withoutExceptionHandling();
        $tokens = isolationSubscribe();
        Event::fake([ManageLinkRequested::class]);

        config()->set($this->guards);

        $this->postJson('/waitlist/manage-link', ['token' => $tokens['unsubscribe']])->assertStatus(202);

        Event::assertDispatched(ManageLinkRequested::class);
    });

    it('are reported, naming the guard', function () {
        Exceptions::fake();

        isolationRun('one-click unsubscribe', $this->guards, $this);

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => $exception->getMessage() === 'The waitlist.authentication.guards config names a guard config/auth.php does not define: no-such-guard.');
    });

    it('still refuse the signup and the manage link by address, rather than reading the caller as a guest', function (string $uri, array $body) {
        $this->withoutExceptionHandling();

        config()->set($this->guards);

        expect(fn () => $this->postJson($uri, $body))->toThrow(InvalidConfigurationException::class, 'no-such-guard');
        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with([
        'the signup' => ['/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()]],
        'the manage link by address' => ['/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta']],
    ]);

    it('still limit the links, by the address the request came from', function () {
        $tokens = array_map(fn (string $email): string => isolationSubscribe(email: $email)['unsubscribe'], ['a@example.com', 'b@example.com', 'c@example.com']);

        config()->set($this->guards);
        config()->set(ConfigKey::LinksPerIpPerMinute->value, 2);

        $statuses = array_map(fn (string $token): int => $this->getJson("/waitlist/unsubscribe/{$token}")->status(), $tokens);

        expect($statuses)->toBe([200, 200, 429]);
    });
});

describe('a client IP header that does not read', function () {
    it('keeps no link of a guest from working, as it is never read for one', function (string $flow, mixed $header) {
        $this->withoutExceptionHandling();

        isolationRun($flow, [ConfigKey::ClientIpHeader->value => $header], $this);
    })->with(function () {
        foreach (['one-click unsubscribe', 'leave from the manage page', 'confirm an issued link over HTTP'] as $flow) {
            yield "{$flow} / a list" => [$flow, ['X-Real-Ip']];
            yield "{$flow} / a number" => [$flow, 5];
        }
    });
});

describe('package routes that are on but not registered under their name', function () {
    it('are named as the cause, with the command that registers them again', function () {
        config()->set(ConfigKey::RoutesName->value, 'elsewhere.');

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist routes are on, but no route is named elsewhere.unsubscribe. Register them again, with php artisan route:cache if the routes are cached, and restart long-running workers.');
    });
});

describe('link limits that do not read', function () {
    $settings = [
        'link_per_minute is not a number' => [ConfigKey::LinkPerMinute->value, 'many'],
        'links_per_ip_per_minute is zero' => [ConfigKey::LinksPerIpPerMinute->value, 0],
    ];

    it('keep no link from working, and are reported', function (string $flow, string $setting, mixed $value) {
        Exceptions::fake();

        isolationRun($flow, [$setting => $value], $this);

        Exceptions::assertReported(fn (InvalidConfigurationException $exception) => str_contains($exception->getMessage(), $setting));
    })->with(isolationCases(['one-click unsubscribe', 'leave from the manage page', 'confirm an issued link over HTTP'], $settings));

    it('give way to the limits the package ships, so the links stay limited', function () {
        $tokens = isolationSubscribe();

        config()->set(ConfigKey::LinkPerMinute->value, 'many');

        $statuses = array_map(fn (): int => $this->getJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->status(), range(1, WaitlistServiceProvider::LINK_PER_MINUTE + 1));

        expect(array_count_values($statuses))->toBe([200 => WaitlistServiceProvider::LINK_PER_MINUTE, 429 => 1]);
    });

    it('fall back to the values config/waitlist.php ships', function () {
        $limits = (require __DIR__.'/../../config/waitlist.php')['routes']['rate_limits'];

        expect([$limits['link_per_minute'], $limits['links_per_ip_per_minute']])
            ->toBe([WaitlistServiceProvider::LINK_PER_MINUTE, WaitlistServiceProvider::LINKS_PER_IP_PER_MINUTE]);
    });
});

describe('the routes switch', function () {
    it('turns the routes off with false', function () {
        $tokens = isolationSubscribe();

        config()->set(ConfigKey::RoutesEnabled->value, false);

        $this->getJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertNotFound();
    });

    it('refuses every request when it is empty, as an empty variable is no decision', function () {
        $tokens = isolationSubscribe();
        $this->withoutExceptionHandling();

        config()->set(ConfigKey::RoutesEnabled->value, '');

        expect(fn () => $this->getJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}"))
            ->toThrow(InvalidConfigurationException::class, ConfigKey::RoutesEnabled->value);
    });
});
