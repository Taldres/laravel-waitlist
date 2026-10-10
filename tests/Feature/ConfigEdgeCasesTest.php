<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;

/*
 * ConfigIsolationTest and FlowIsolationTest break the settings a flow was found
 * to read by mistake. This breaks every setting a flow does not need, at once,
 * so that a new dependency on an unrelated one is found as well. Each flow
 * lists what it needs; a setting that cannot be read is an object, whatever
 * type it wants.
 */

/**
 * @return array{entry: WaitlistEntry, confirm: string|null, unsubscribe: string}
 */
function edgeCaseSubscribe(bool $confirm = true): array
{
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);

    if ($confirm) {
        Waitlist::confirm($tokens['confirm']);
    }

    return $tokens;
}

function edgeCaseEntry(): WaitlistEntry
{
    return WaitlistEntry::query()->firstOrFail();
}

/**
 * The settings, and the groups of settings that hold none the flow needs,
 * shallowest first, so that a group broken as a whole is not broken again below.
 *
 * @param  list<ConfigKey>  $needs
 * @return list<string>
 */
function edgeCaseUnrelated(array $needs): array
{
    $needs = array_map(fn (ConfigKey $key): string => $key->value, $needs);
    $candidates = [];

    foreach (ConfigKey::cases() as $key) {
        $segments = explode('.', $key->value);

        for ($depth = 2; $depth <= count($segments); $depth++) {
            $candidates[implode('.', array_slice($segments, 0, $depth))] = $depth;
        }
    }

    asort($candidates);

    $broken = [];

    foreach (array_keys($candidates) as $path) {
        $isNeeded = array_filter($needs, fn (string $need): bool => $need === $path || str_starts_with($need, "{$path}."));
        $isInsideBroken = array_filter($broken, fn (string $parent): bool => str_starts_with($path, "{$parent}."));

        if ($isNeeded === [] && $isInsideBroken === []) {
            $broken[] = $path;
        }
    }

    return $broken;
}

/**
 * Every flow with the settings it needs, whether it goes over HTTP, and the
 * arrangement that returns the step which must still work.
 *
 * @return array<string, array{0: list<ConfigKey>, 1: bool, 2: Closure(mixed): Closure(): void}>
 */
function edgeCaseFlows(): array
{
    $store = [ConfigKey::Model, ConfigKey::SubscriptionModel, ConfigKey::ConsentModel, ConfigKey::ActivityModel, ConfigKey::Connection];
    // The limits of the links fall back to the shipped ones when they do not read.
    $links = [ConfigKey::RoutesEnabled, ConfigKey::RoutesPrefix, ConfigKey::RoutesName, ConfigKey::RoutesMiddleware, ConfigKey::LinksMiddleware, ConfigKey::LinksLimiter];
    $signup = [ConfigKey::RoutesEnabled, ConfigKey::RoutesPrefix, ConfigKey::RoutesName, ConfigKey::RoutesMiddleware, ConfigKey::SignupMiddleware, ConfigKey::SignupLimiter, ConfigKey::SignupPerMinute];
    $manageLink = [ConfigKey::ManageTokenTtl, ConfigKey::ManageRequestCooldown, ConfigKey::RoutesEnabled, ConfigKey::EmailNormalizer, ConfigKey::UrlGenerator, ConfigKey::Catalog];
    $erasure = [ConfigKey::Model, ConfigKey::ActivityModel, ConfigKey::Connection, ConfigKey::EmailNormalizer];
    $retention = [ConfigKey::RetentionPendingDays, ConfigKey::RetentionUnsubscribedDays, ConfigKey::RetentionRequestMetadataDays];

    $manage = fn (): string => Waitlist::manageLink(edgeCaseEntry())->token;
    $unsubscribed = fn (): bool => edgeCaseEntry()->status === EntryStatus::Unsubscribed;
    $newsletterWithdrawn = fn (): bool => WaitlistConsent::query()->where('purpose', 'newsletter')->whereNotNull('withdrawn_at')->exists();

    return [
        'unsubscribe by token' => [$store, false, function ($test) use ($unsubscribed): Closure {
            $tokens = edgeCaseSubscribe();

            return function () use ($tokens, $unsubscribed): void {
                Waitlist::unsubscribe($tokens['unsubscribe']);

                expect($unsubscribed())->toBeTrue();
            };
        }],
        'one-click unsubscribe' => [[...$store, ...$links], true, function ($test) use ($unsubscribed): Closure {
            $tokens = edgeCaseSubscribe();

            return function () use ($test, $tokens, $unsubscribed): void {
                $test->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

                expect($unsubscribed())->toBeTrue();
            };
        }],
        'withdraw one purpose' => [$store, false, function ($test) use ($newsletterWithdrawn): Closure {
            $tokens = edgeCaseSubscribe();

            return function () use ($tokens, $newsletterWithdrawn): void {
                Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

                expect($newsletterWithdrawn())->toBeTrue();
            };
        }],
        'unsubscribe page state' => [[...$store, ...$links], true, function ($test): Closure {
            $tokens = edgeCaseSubscribe();

            return function () use ($test, $tokens): void {
                $test->getJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertOk()->assertJsonPath('data.status', EntryStatus::Confirmed->value);
            };
        }],
        'confirm an issued link' => [[...$store, ConfigKey::UrlGenerator, ConfigKey::Catalog, ConfigKey::RoutesEnabled], false, function ($test): Closure {
            $tokens = edgeCaseSubscribe(confirm: false);

            return function () use ($tokens): void {
                expect(Waitlist::confirm($tokens['confirm'])->status)->toBe(EntryStatus::Confirmed)
                    ->and(edgeCaseEntry()->status)->toBe(EntryStatus::Confirmed);
            };
        }],
        'confirm an issued link over HTTP' => [[...$store, ...$links, ConfigKey::UrlGenerator, ConfigKey::Catalog], true, function ($test): Closure {
            $tokens = edgeCaseSubscribe(confirm: false);

            return function () use ($test, $tokens): void {
                $test->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk()->assertJsonPath('data.status', EntryStatus::Confirmed->value);
            };
        }],
        'confirm page state' => [[...$store, ...$links], true, function ($test): Closure {
            $tokens = edgeCaseSubscribe(confirm: false);

            return function () use ($test, $tokens): void {
                $test->getJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk()->assertJsonPath('data.status', EntryStatus::Pending->value);
            };
        }],
        'manage link requested by token' => [[...$store, ...$manageLink], false, function ($test): Closure {
            $tokens = edgeCaseSubscribe();
            Event::fake([ManageLinkRequested::class]);

            return function () use ($tokens): void {
                expect(Waitlist::requestManageLink($tokens['unsubscribe']))->toBeTrue();

                Event::assertDispatched(ManageLinkRequested::class);
            };
        }],
        'manage link requested by token over HTTP' => [[...$store, ...$manageLink, ...$links], true, function ($test): Closure {
            $tokens = edgeCaseSubscribe();
            Event::fake([ManageLinkRequested::class]);

            return function () use ($test, $tokens): void {
                $test->postJson("/waitlist/unsubscribe/{$tokens['unsubscribe']}/manage-link")->assertStatus(202);

                Event::assertDispatched(ManageLinkRequested::class);
            };
        }],
        'manage link requested by address' => [[...$store, ...$manageLink], false, function ($test): Closure {
            edgeCaseSubscribe();
            Event::fake([ManageLinkRequested::class]);

            return function (): void {
                expect(Waitlist::for('beta')->requestManageLink('user@example.com'))->toBeTrue();

                Event::assertDispatched(ManageLinkRequested::class);
            };
        }],
        'manage link requested by address over HTTP' => [[...$store, ...$manageLink, ...$signup, ConfigKey::SpamProtector, ConfigKey::ProjectResolver, ConfigKey::AuthenticationRequired, ConfigKey::AuthenticationGuards], true, function ($test): Closure {
            edgeCaseSubscribe();
            Event::fake([ManageLinkRequested::class]);

            return function () use ($test): void {
                $test->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertStatus(202);

                Event::assertDispatched(ManageLinkRequested::class);
            };
        }],
        'manage link issued for an identified person' => [[ConfigKey::Model, ConfigKey::Connection, ConfigKey::ManageTokenTtl, ConfigKey::RoutesEnabled, ConfigKey::UrlGenerator, ConfigKey::Catalog], false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                $link = Waitlist::manageLink(edgeCaseEntry());

                expect(Waitlist::findByManageToken($link->token))->not->toBeNull();
            };
        }],
        'manage page: show' => [[...$store, ...$links], true, function ($test) use ($manage): Closure {
            edgeCaseSubscribe();
            $token = $manage();

            return function () use ($test, $token): void {
                $test->getJson("/waitlist/manage/{$token}")->assertOk()->assertJsonPath('data.status', EntryStatus::Confirmed->value);
            };
        }],
        'manage page: update purposes' => [[...$store, ...$links, ConfigKey::Catalog], true, function ($test) use ($manage, $newsletterWithdrawn): Closure {
            edgeCaseSubscribe();
            $token = $manage();

            return function () use ($test, $token, $newsletterWithdrawn): void {
                $test->putJson("/waitlist/manage/{$token}/purposes", ['purposes' => waitlistConsent()])->assertOk();

                expect($newsletterWithdrawn())->toBeTrue();
            };
        }],
        'manage page: leave' => [[...$store, ...$links], true, function ($test) use ($manage, $unsubscribed): Closure {
            edgeCaseSubscribe();
            $token = $manage();

            return function () use ($test, $token, $unsubscribed): void {
                $test->postJson("/waitlist/manage/{$token}/unsubscribe")->assertOk();

                expect($unsubscribed())->toBeTrue();
            };
        }],
        'manage page: export data' => [[...$store, ...$links], true, function ($test) use ($manage): Closure {
            edgeCaseSubscribe();
            $token = $manage();

            return function () use ($test, $token): void {
                $test->postJson("/waitlist/manage/{$token}/data")->assertOk()->assertJsonPath('email', 'user@example.com');
            };
        }],
        'manage page: erase' => [[...$store, ...$links], true, function ($test) use ($manage): Closure {
            edgeCaseSubscribe();
            $token = $manage();

            return function () use ($test, $token): void {
                $test->postJson("/waitlist/manage/{$token}/erase", ['confirm' => true])->assertOk();

                expect(WaitlistEntry::query()->count())->toBe(0);
            };
        }],
        'waitlist:forget a whole list' => [[ConfigKey::Model, ConfigKey::ActivityModel, ConfigKey::Connection], false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                expect(Artisan::call('waitlist:forget', ['--all' => true, '--list' => 'beta', '--force' => true]))->toBe(0)
                    ->and(WaitlistEntry::query()->count())->toBe(0);
            };
        }],
        'waitlist:forget by address' => [$erasure, false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                expect(Artisan::call('waitlist:forget', ['email' => 'user@example.com']))->toBe(0)
                    ->and(WaitlistEntry::query()->count())->toBe(0);
            };
        }],
        'waitlist:prune' => [[...$store, ...$retention], false, function ($test): Closure {
            subscribeAndCapture('beta', 'pending@example.com');

            $left = subscribeAndCapture('beta', 'left@example.com');
            Waitlist::confirm($left['confirm']);
            Waitlist::unsubscribe($left['unsubscribe']);

            $active = subscribeAndCapture('beta', 'active@example.com');
            Waitlist::confirm($active['confirm']);

            // Plain text in an encrypted column: only its presence matters here.
            WaitlistActivity::query()->update(['ip' => '203.0.113.9']);
            $test->travel(1100)->days();

            return function (): void {
                expect(Artisan::call('waitlist:prune'))->toBe(0)
                    ->and(WaitlistEntry::query()->pluck('email')->all())->toBe(['active@example.com'])
                    ->and(WaitlistActivity::query()->whereNotNull('ip')->count())->toBe(0);
            };
        }],
        'waitlist:export' => [[ConfigKey::Model, ConfigKey::SubscriptionModel, ConfigKey::ConsentModel, ConfigKey::Connection, ConfigKey::ExportColumns, ConfigKey::ExportSpreadsheetSafe], false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                $path = sys_get_temp_dir().'/waitlist-isolation-'.bin2hex(random_bytes(4)).'.csv';

                try {
                    expect(Artisan::call('waitlist:export', ['list' => 'beta', '--path' => $path]))->toBe(0)
                        ->and((string) file_get_contents($path))->toContain('user@example.com');
                } finally {
                    @unlink($path);
                }
            };
        }],
        'waitlist:privacy' => [[
            ...$retention, ConfigKey::Catalog, ConfigKey::DoubleOptIn, ConfigKey::ResendCooldown, ConfigKey::MaxConfirmations, ConfigKey::MaxPendingPerAddress, ConfigKey::ManageTokenTtl,
            ConfigKey::StoreIp, ConfigKey::StoreUserAgent, ConfigKey::RetentionSchedule, ConfigKey::RoutesEnabled,
        ], false, function ($test): Closure {
            return function (): void {
                expect(Artisan::call('waitlist:privacy'))->toBe(0)
                    ->and(Artisan::output())->toContain('Waitlist processing record');
            };
        }],
        'waitlist:show' => [[...$store, ConfigKey::EmailNormalizer], false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                expect(Artisan::call('waitlist:show', ['email' => 'user@example.com', '--json' => true]))->toBe(0)
                    ->and(Artisan::output())->toContain('user@example.com');
            };
        }],
        'Waitlist::forget' => [$erasure, false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                expect(Waitlist::forget('user@example.com'))->toBe(1)
                    ->and(WaitlistEntry::query()->count())->toBe(0);
            };
        }],
        'Waitlist::personalData' => [[...$store, ConfigKey::EmailNormalizer], false, function ($test): Closure {
            edgeCaseSubscribe();

            return function (): void {
                expect(Waitlist::personalData('user@example.com'))->toHaveCount(1);
            };
        }],
    ];
}

/**
 * Arranges the flow with a readable config, sets the settings, and runs it.
 *
 * @param  array<string, mixed>  $settings
 */
function edgeCaseRun(string $flow, array $settings, mixed $test): void
{
    [, $http, $arrange] = edgeCaseFlows()[$flow];

    if ($http) {
        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);

        require __DIR__.'/../../routes/waitlist.php';
    }

    $act = $arrange($test);

    foreach ($settings as $path => $value) {
        config()->set($path, $value);
    }

    $act();
}

describe('a flow with every setting it does not read broken at once', function () {
    it('still works', function (string $flow) {
        $this->withoutExceptionHandling();

        $unrelated = edgeCaseUnrelated(edgeCaseFlows()[$flow][0]);

        expect($unrelated)->not->toBeEmpty();

        edgeCaseRun($flow, array_map(fn (): stdClass => new stdClass, array_flip($unrelated)), $this);
    })->with(fn () => array_keys(edgeCaseFlows()));
});
