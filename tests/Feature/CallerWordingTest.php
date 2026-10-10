<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Taldres\Waitlist\Actions\SyncPurposes;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Events\WordingRegistered;
use Taldres\Waitlist\Exceptions\WordingNotAcceptedException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;
use Taldres\Waitlist\Tests\Fixtures\ProjectCaller;

const CALLER_WORDING = 'Email me when Acme launches.';

/**
 * @param  array<string, mixed>  $purposes
 */
function callerWordingSignup(array $purposes, ?string $server = 'acme', string $email = 'user@example.com', string $project = 'acme'): TestResponse
{
    // The request guard keeps its user from the previous request.
    Auth::forgetGuards();

    return test()->postJson('/waitlist', ['email' => $email, 'list' => 'beta', 'purposes' => $purposes], array_filter([
        'X-Waitlist-Project' => $project,
        'X-Server' => $server,
    ]));
}

/**
 * @return array<string, array{version: string, text: string, locale?: string}>
 */
function callerWordingChoice(string $text = CALLER_WORDING, string $version = '2026-10', ?string $locale = null): array
{
    return ['launch' => array_filter(['version' => $version, 'locale' => $locale, 'text' => $text])];
}

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);

    require __DIR__.'/../../routes/waitlist.php';

    Auth::viaRequest('waitlist-server', fn (Request $request) => is_string($project = $request->header('X-Server')) ? new ProjectCaller($project) : null);
    config()->set('auth.guards.server', ['driver' => 'waitlist-server']);
    config()->set(ConfigKey::AuthenticationGuards->value, ['server']);

    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->wordingFromCallers();
        $project->list('beta', purpose: 'launch')->optional('newsletter');
    });

    Waitlist::define('plain', function (ProjectDefinition $project): void {
        $project->purpose('launch', ['2026-10' => CALLER_WORDING]);
        $project->list('beta', purpose: 'launch');
    });
});

describe('a server of the project', function () {
    it('registers the wording it sends with the first signup, and stores it as the consent', function () {
        Event::fake([WordingRegistered::class]);

        callerWordingSignup(callerWordingChoice())->assertStatus(202);

        $wording = WaitlistWording::query()->sole();

        expect($wording->only(['project', 'purpose', 'version', 'locale', 'text', 'registered_by']))->toBe([
            'project' => 'acme',
            'purpose' => 'launch',
            'version' => '2026-10',
            'locale' => '',
            'text' => CALLER_WORDING,
            'registered_by' => ProjectCaller::class.'#project:acme',
        ])->and(WaitlistConsent::query()->sole()->only(['purpose', 'version', 'text']))->toBe([
            'purpose' => 'launch',
            'version' => '2026-10',
            'text' => CALLER_WORDING,
        ]);

        Event::assertDispatchedTimes(WordingRegistered::class, 1);
    });

    it('needs no wording in the definition: the project starts empty and lists what its servers sent', function () {
        $this->getJson('/waitlist/purposes?list=beta', ['X-Waitlist-Project' => 'acme', 'X-Server' => 'acme'])
            ->assertOk()
            ->assertExactJson(['data' => []]);

        callerWordingSignup([...callerWordingChoice(), 'newsletter' => ['version' => '2026-10', 'text' => 'Send me the newsletter.']])->assertStatus(202);

        Auth::forgetGuards();
        $this->getJson('/waitlist/purposes?list=beta', ['X-Waitlist-Project' => 'acme', 'X-Server' => 'acme'])
            ->assertOk()
            ->assertJsonPath('data.*.purpose', ['launch', 'newsletter'])
            ->assertJsonPath('data.0.text', CALLER_WORDING);
    });

    it('takes the same text again without registering it twice', function () {
        Event::fake([WordingRegistered::class]);

        callerWordingSignup(callerWordingChoice())->assertStatus(202);
        callerWordingSignup(callerWordingChoice(), email: 'other@example.com')->assertStatus(202);

        expect(WaitlistWording::query()->count())->toBe(1)
            ->and(WaitlistConsent::query()->pluck('text')->all())->toBe([CALLER_WORDING, CALLER_WORDING]);

        Event::assertDispatchedTimes(WordingRegistered::class, 1);
    });

    it('refuses other text for a registered version, and signs nobody up with it', function () {
        callerWordingSignup(callerWordingChoice())->assertStatus(202);

        callerWordingSignup(callerWordingChoice('Email me about everything.'), email: 'other@example.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purposes' => 'The wording of [launch] version [2026-10] is registered with other text. Register a new version instead.']);

        expect(WaitlistWording::query()->pluck('text')->all())->toBe([CALLER_WORDING])
            ->and(WaitlistEntry::query()->count())->toBe(1);
    });

    it('takes a new version as the current one, and keeps the old one for forms still showing it', function () {
        callerWordingSignup(callerWordingChoice())->assertStatus(202);
        callerWordingSignup(callerWordingChoice('Tell me when Acme launches.', '2026-11'), email: 'two@example.com')->assertStatus(202);
        callerWordingSignup(['launch' => '2026-10'], email: 'three@example.com')->assertStatus(202);

        expect(Waitlist::project('acme')->purposes('beta')[0]->version)->toBe('2026-11')
            ->and(WaitlistConsent::query()->orderBy('id')->pluck('version')->all())->toBe(['2026-10', '2026-11', '2026-10']);
    });

    it('adds a locale to a registered version, but never mixes it with one text for every locale', function () {
        callerWordingSignup(callerWordingChoice('Sagt mir, wenn Acme startet.', locale: 'de'))->assertStatus(202);
        callerWordingSignup(callerWordingChoice(locale: 'en'), email: 'two@example.com')->assertStatus(202);

        expect(WaitlistWording::query()->orderBy('id')->pluck('locale')->all())->toBe(['de', 'en'])
            ->and(WaitlistConsent::query()->orderBy('id')->pluck('locale')->all())->toBe(['de', 'en']);

        callerWordingSignup(callerWordingChoice(), email: 'three@example.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purposes' => 'The wording of [launch] version [2026-10] holds either one text for every locale or a text per locale, not both.']);
    });

    it('never brings back a version the operator retired', function () {
        callerWordingSignup(callerWordingChoice())->assertStatus(202);
        Waitlist::project('acme')->retireWording('launch', '2026-10');

        callerWordingSignup(callerWordingChoice(), email: 'other@example.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purposes' => 'The wording of [launch] version [2026-10] is retired. Register a new version instead.']);

        expect(WaitlistWording::query()->sole()->retired_at)->not->toBeNull();
    });

    it('tries again when the first registration of a version deadlocks with another', function () {
        $deadlocked = false;

        WaitlistWording::creating(function () use (&$deadlocked): void {
            if (! $deadlocked) {
                $deadlocked = true;

                throw new QueryException('testing', 'insert into waitlist_wordings', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        callerWordingSignup(callerWordingChoice())->assertStatus(202);

        expect($deadlocked)->toBeTrue()
            ->and(WaitlistWording::query()->sole()->text)->toBe(CALLER_WORDING);
    });

    it('keeps a version given in the definition: the text sent must read the same', function () {
        Waitlist::define('acme', function (ProjectDefinition $project): void {
            $project->wordingFromCallers();
            $project->purpose('launch', ['2026-10' => CALLER_WORDING]);
            $project->list('beta', purpose: 'launch');
        });

        callerWordingSignup(callerWordingChoice())->assertStatus(202);
        callerWordingSignup(callerWordingChoice('Email me about everything.'), email: 'other@example.com')->assertStatus(422);

        expect(WaitlistWording::query()->count())->toBe(0);
    });

    it('checks a hash sent along with the text, in either case', function () {
        $hash = hash('sha256', CALLER_WORDING);

        callerWordingSignup(['launch' => ['version' => '2026-10', 'text' => CALLER_WORDING, 'hash' => hash('sha256', 'other')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purposes' => 'The wording shown for [launch] does not match version [2026-10].']);

        callerWordingSignup(['launch' => ['version' => '2026-10', 'text' => CALLER_WORDING, 'hash' => strtoupper($hash)]])->assertStatus(202);
    });

    it('lets anyone the gate admits sign up with the version alone once it is registered', function () {
        callerWordingSignup(callerWordingChoice())->assertStatus(202);

        callerWordingSignup(['launch' => ['version' => '2026-10', 'hash' => hash('sha256', CALLER_WORDING)]], server: null, email: 'guest@example.com')->assertStatus(202);

        expect(WaitlistConsent::query()->orderBy('id')->pluck('text')->all())->toBe([CALLER_WORDING, CALLER_WORDING]);
    });

    it('refuses wording for a project that keeps it in the catalog', function () {
        callerWordingSignup(callerWordingChoice(), server: 'plain', project: 'plain')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purposes' => 'The wording of [launch] comes from the catalog here; send the version that was shown, without its text.']);
    });

    it('explains what is wrong with a choice', function (array $choice, string $message) {
        callerWordingSignup(['launch' => $choice])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purposes.launch' => $message]);
    })->with([
        'empty text' => [['version' => '2026-10', 'text' => '  '], 'The purposes.launch.text field must be the wording that was shown.'],
        'text too long' => [['version' => '2026-10', 'text' => str_repeat('a', 10001)], 'The purposes.launch.text field may not be longer than 10000 characters.'],
        'version too long for text' => [['version' => str_repeat('v', 101), 'text' => CALLER_WORDING], 'The purposes.launch.version field may not be longer than 100 characters when it comes with its text.'],
        'no version' => [['text' => CALLER_WORDING], 'The purposes.launch.version field must be a version of 1 to 255 characters.'],
        'malformed hash' => [['version' => '2026-10', 'hash' => '0000'], 'The purposes.launch.hash field must be the SHA-256 of the wording shown, as 64 hex characters.'],
        'unknown key' => [['version' => '2026-10', 'admin' => true], 'The purposes.launch field may only have the keys version, locale, hash, text.'],
    ]);
});

describe('who may send wording', function () {
    it('refuses it from guests, before the body is looked at', function () {
        callerWordingSignup(callerWordingChoice(), server: null)
            ->assertForbidden()
            ->assertJsonPath('message', "Only the project's own servers may send wording; send the version that was shown.");

        expect(WaitlistWording::query()->count())->toBe(0);
    });

    it('refuses it from the servers of other projects', function () {
        callerWordingSignup(callerWordingChoice(), server: 'plain')->assertNotFound();

        expect(WaitlistWording::query()->count())->toBe(0);
    });

    it('lets the app decide with the useWaitlist gate', function () {
        Gate::define(WaitlistGate::ABILITY, fn (mixed $caller, string $project, WaitlistAction $action): Response => $action === WaitlistAction::RegisterWording
            ? Response::deny('Wording is frozen until the launch.')
            : Response::allow());

        callerWordingSignup(callerWordingChoice())
            ->assertForbidden()
            ->assertJsonPath('message', 'Wording is frozen until the launch.');
    });

    it('accepts it from the app itself, without a caller to record', function () {
        Waitlist::project('acme')->for('beta')->add('user@example.com', callerWordingChoice());

        expect(WaitlistWording::query()->sole()->registered_by)->toBeNull()
            ->and(WaitlistConsent::query()->sole()->text)->toBe(CALLER_WORDING);
    });

    it('never takes it on the preference page, where the mailbox owner chooses', function () {
        callerWordingSignup(callerWordingChoice())->assertStatus(202);
        $entry = WaitlistEntry::query()->sole();

        $this->putJson('/waitlist/manage/'.manageTokenFor($entry).'/purposes', ['purposes' => [
            'launch' => ['version' => '2026-10', 'text' => 'I agree to everything.'],
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'purposes.launch' => 'The purposes.launch field may not carry its text: the wording comes from the catalog; send the version that was shown.',
        ]);

        expect(fn () => app(SyncPurposes::class)($entry, callerWordingChoice('I agree to everything.')))
            ->toThrow(WordingNotAcceptedException::class);
    });
});

it('tells the record of processing that the wording is still to come', function () {
    Artisan::call('waitlist:privacy');

    expect(Artisan::output())->toContain("| acme | beta | launch | yes | — | None yet: the project's servers send it with the first signup. | yes |");
});
