<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;

/*
 * Leaving, withdrawing, requesting a manage link by token, erasing and
 * exporting must work whatever a setting they do not need says. Each flow is
 * arranged with a readable config, then the setting is broken and the flow runs.
 */

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';
});

/**
 * @return array{entry: WaitlistEntry, confirm: string|null, unsubscribe: string}
 */
function flowIsolationSubscribe(string $list = 'beta', string $email = 'user@example.com'): array
{
    $tokens = subscribeAndCapture($list, $email, [...waitlistConsent(), 'newsletter' => '2026-10']);

    Waitlist::confirm($tokens['confirm']);

    return $tokens;
}

function flowIsolationNewsletterWithdrawnOn(string $list): bool
{
    return WaitlistConsent::query()
        ->where('purpose', 'newsletter')
        ->whereNotNull('withdrawn_at')
        ->whereHas('subscription.entry', fn ($entry) => $entry->where('list', $list))
        ->exists();
}

function flowIsolationEntry(): WaitlistEntry
{
    return WaitlistEntry::query()->firstOrFail();
}

function flowIsolationReportedFor(ConfigKey $setting): Closure
{
    return fn (InvalidConfigurationException $exception): bool => str_contains($exception->getMessage(), $setting->value);
}

/**
 * Each flow arranges what it needs and returns the step that must still work,
 * with its assertions.
 *
 * @return array<string, Closure(mixed): Closure(): void>
 */
function flowIsolationLeaving(): array
{
    return [
        'unsubscribe by token' => function ($test): Closure {
            $tokens = flowIsolationSubscribe();

            return function () use ($tokens): void {
                Waitlist::unsubscribe($tokens['unsubscribe']);

                expect(flowIsolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'withdraw the primary purpose by token' => function ($test): Closure {
            $tokens = flowIsolationSubscribe();

            return function () use ($tokens): void {
                Waitlist::withdrawConsent($tokens['unsubscribe'], 'waitlist');

                expect(flowIsolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'withdraw an optional purpose by token' => function ($test): Closure {
            $tokens = flowIsolationSubscribe();

            return function () use ($tokens): void {
                Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

                expect(flowIsolationNewsletterWithdrawnOn('beta'))->toBeTrue()
                    ->and(flowIsolationEntry()->status)->toBe(EntryStatus::Confirmed);
            };
        },
        'one-click unsubscribe' => function ($test): Closure {
            $tokens = flowIsolationSubscribe();

            return function () use ($test, $tokens): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                    ->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])
                    ->assertOk();

                expect(flowIsolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'unsubscribe from a browser' => function ($test): Closure {
            $tokens = flowIsolationSubscribe();

            return function () use ($test, $tokens): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertOk();

                expect(flowIsolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
        'withdraw a purpose from a browser' => function ($test): Closure {
            $tokens = flowIsolationSubscribe();

            return function () use ($test, $tokens): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter")->assertOk();

                expect(flowIsolationNewsletterWithdrawnOn('beta'))->toBeTrue();
            };
        },
        'leave from the manage page' => function ($test): Closure {
            flowIsolationSubscribe();
            $manage = Waitlist::manageLink(flowIsolationEntry())->token;

            return function () use ($test, $manage): void {
                $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson("/waitlist/manage/{$manage}/unsubscribe")->assertOk();

                expect(flowIsolationEntry()->status)->toBe(EntryStatus::Unsubscribed);
            };
        },
    ];
}

describe('an email normalizer that does not work', function () {
    it('keeps no link from ending the subscription it names, and is reported', function (string $flow) {
        Exceptions::fake();

        $act = flowIsolationLeaving()[$flow]($this);

        config()->set(ConfigKey::EmailNormalizer->value, stdClass::class);

        $act();

        Exceptions::assertReported(flowIsolationReportedFor(ConfigKey::EmailNormalizer));
    })->with(array_keys(flowIsolationLeaving()));

    it('is reported once for a request, however many lists the sweep would have covered', function () {
        Exceptions::fake();
        $tokens = flowIsolationSubscribe('beta');
        flowIsolationSubscribe('gamma');

        config()->set(ConfigKey::EmailNormalizer->value, stdClass::class);

        Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

        Exceptions::assertReportedCount(1);
    });

    it('leaves the address on its other lists until the withdrawal is repeated with a working one', function () {
        Exceptions::fake();
        $normalizer = config(ConfigKey::EmailNormalizer->value);
        $tokens = flowIsolationSubscribe('beta');
        flowIsolationSubscribe('gamma');

        config()->set(ConfigKey::EmailNormalizer->value, stdClass::class);

        Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

        expect(flowIsolationNewsletterWithdrawnOn('beta'))->toBeTrue()
            ->and(flowIsolationNewsletterWithdrawnOn('gamma'))->toBeFalse();

        config()->set(ConfigKey::EmailNormalizer->value, $normalizer);

        Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

        expect(flowIsolationNewsletterWithdrawnOn('gamma'))->toBeTrue();
    });
});

describe('a manage link requested by token', function () {
    beforeEach(function () {
        $this->tokens = flowIsolationSubscribe();
        Event::fake([ManageLinkRequested::class]);
    });

    it('is mailed whatever the spam protector or the project resolver say', function (ConfigKey $setting) {
        $this->withoutExceptionHandling();

        config()->set($setting->value, stdClass::class);

        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}/manage-link")->assertStatus(202);

        Event::assertDispatchedTimes(ManageLinkRequested::class, 1);
    })->with([
        'spam_protector' => ConfigKey::SpamProtector,
        'project_resolver' => ConfigKey::ProjectResolver,
    ]);

    it('is still refused by address when the setting the address needs does not work', function (ConfigKey $setting) {
        $this->withoutExceptionHandling();

        config()->set($setting->value, stdClass::class);

        expect(fn () => $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta']))
            ->toThrow(InvalidConfigurationException::class, $setting->value);

        Event::assertNotDispatched(ManageLinkRequested::class);
    })->with([
        'spam_protector' => ConfigKey::SpamProtector,
        'project_resolver' => ConfigKey::ProjectResolver,
    ]);
});

describe('erasing and exporting the default project', function () {
    it('needs no catalog', function () {
        $this->withoutExceptionHandling();
        flowIsolationSubscribe('beta');
        flowIsolationSubscribe('gamma');

        config()->set(ConfigKey::Catalog->value, stdClass::class);

        expect(Waitlist::personalData('user@example.com'))->toHaveCount(2)
            ->and(Waitlist::personalData('user@example.com', 'beta'))->toHaveCount(1)
            ->and(Waitlist::forget('user@example.com', 'beta'))->toBe(1)
            ->and(Waitlist::forget('user@example.com'))->toBe(1)
            ->and(WaitlistEntry::query()->count())->toBe(0);
    });

    it('needs no catalog across all projects either', function () {
        $this->withoutExceptionHandling();
        flowIsolationSubscribe('beta');

        config()->set(ConfigKey::Catalog->value, stdClass::class);

        expect(Waitlist::allProjects()->personalData('user@example.com'))->toHaveCount(1)
            ->and(Waitlist::allProjects()->forget('user@example.com'))->toBe(1);
    });

    it('still asks the catalog before it reaches another project', function () {
        config()->set(ConfigKey::Catalog->value, stdClass::class);

        expect(fn () => Waitlist::project('acme'))->toThrow(InvalidConfigurationException::class, ConfigKey::Catalog->value);
    });
});

describe('a browser that unsubscribed', function () {
    it('is sent to the goodbye page of the project', function () {
        defineDefaultProject(fn ($project) => $project->urls(unsubscribed: 'https://app.test/goodbye'));
        $tokens = flowIsolationSubscribe();

        $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}")->assertRedirect('https://app.test/goodbye');
    });

    it('is answered in JSON when the catalog cannot be read once the subscription ended, and the catalog is reported', function (string $query, EntryStatus $status) {
        Exceptions::fake();
        defineDefaultProject(fn ($project) => $project->urls(unsubscribed: 'https://app.test/goodbye'));
        $tokens = flowIsolationSubscribe();

        config()->set(ConfigKey::Catalog->value, stdClass::class);

        $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}{$query}")->assertOk()->assertJsonPath('data.status', $status->value);

        expect(flowIsolationEntry()->status)->toBe($status);
        Exceptions::assertReported(flowIsolationReportedFor(ConfigKey::Catalog));
    })->with([
        'leaving the list' => ['', EntryStatus::Unsubscribed],
        'withdrawing a purpose' => ['?purpose=newsletter', EntryStatus::Confirmed],
    ]);
    // Confirming is no such step: it builds the unsubscribe link from the
    // catalog, so it is undone instead.
    it('is answered in JSON after leaving or erasing on the manage page as well', function (string $step) {
        Exceptions::fake();
        flowIsolationSubscribe();
        $manage = Waitlist::manageLink(flowIsolationEntry())->token;

        config()->set(ConfigKey::Catalog->value, stdClass::class);

        $this->post("/waitlist/manage/{$manage}/{$step}", ['confirm' => true])->assertOk();

        Exceptions::assertReported(flowIsolationReportedFor(ConfigKey::Catalog));
    })->with(['unsubscribe', 'erase']);
});
