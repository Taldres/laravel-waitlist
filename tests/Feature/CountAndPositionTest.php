<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;
use Taldres\Waitlist\Tests\TestCase;

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    app()->bind(ProjectResolver::class, HeaderProjectResolver::class);

    require __DIR__.'/../../routes/waitlist.php';
    Route::getRoutes()->refreshNameLookups();

    $this->withHeader('X-Waitlist-Project', 'default');
    Cache::flush();

    defineDefaultProject(function (ProjectDefinition $project): void {
        $project->list('beta', purpose: 'waitlist')->doubleOptIn()->publicCount()->publicPosition();
        $project->list('quiet', purpose: 'waitlist')->doubleOptIn();
        $project->list('instant', purpose: 'waitlist')->doubleOptIn(false)->publicCount()->publicPosition();
    });

    Waitlist::define('acme', function (ProjectDefinition $project): void {
        TestCase::defineTestPurposes($project);
        $project->origins(['https://acme.example']);
        $project->list('beta', purpose: 'waitlist')->doubleOptIn()->publicCount();
    });
});

/**
 * Confirmed signups, one second apart, so their order is the order given.
 *
 * @return list<string> their unsubscribe tokens
 */
function confirmedSignups(int $count, string $list = 'beta', string $project = 'default', string $prefix = 'user'): array
{
    $tokens = [];

    foreach (range(1, $count) as $n) {
        test()->travel(1)->seconds();
        $captured = subscribeAndCapture($list, "{$prefix}{$n}@example.com", project: $project);

        // A list without double opt-in confirms at once and issues no confirm token.
        if ($captured['confirm'] !== null) {
            Waitlist::confirm($captured['confirm']);
        }
        $tokens[] = $captured['unsubscribe'];
    }

    return $tokens;
}

function positionOf(string $token): mixed
{
    return test()->getJson("/waitlist/unsubscribe/{$token}/position")->assertOk()->json('data.position');
}

describe('the count', function () {
    it('is off unless the list publishes it', function () {
        confirmedSignups(12, 'quiet');

        $this->getJson('/waitlist/count?list=quiet')
            ->assertNotFound()
            ->assertJson(['error' => 'count_disabled']);
    });

    it('answers unknown_list for a list the project does not have', function () {
        $this->getJson('/waitlist/count?list=nope', ['X-Waitlist-Project' => 'acme'])
            ->assertNotFound()
            ->assertJson(['error' => 'unknown_list']);
    });

    it('is withheld below 10, so a small list does not show each signup', function () {
        confirmedSignups(9);

        $this->getJson('/waitlist/count?list=beta')
            ->assertOk()
            ->assertExactJson(['data' => ['list' => 'beta', 'count' => null]]);
    });

    it('counts confirmed addresses only, rounded down to a multiple of 10', function () {
        $tokens = confirmedSignups(21);
        Waitlist::unsubscribe($tokens[0]);
        Waitlist::unsubscribe($tokens[1]);
        foreach (range(1, 5) as $n) {
            subscribeAndCapture('beta', "waiting{$n}@example.com");
        }

        expect($this->getJson('/waitlist/count?list=beta')->assertOk()->json('data.count'))->toBe(10);
    });

    it('is cached for a minute', function () {
        confirmedSignups(10);
        expect($this->getJson('/waitlist/count?list=beta')->json('data.count'))->toBe(10);

        confirmedSignups(10, prefix: 'later');
        expect($this->getJson('/waitlist/count?list=beta')->json('data.count'))->toBe(10);

        $this->travel(61)->seconds();
        expect($this->getJson('/waitlist/count?list=beta')->json('data.count'))->toBe(20);
    });

    it('is off on a list without double opt-in, where a typed-in address would move it', function () {
        confirmedSignups(12, 'instant');

        $this->getJson('/waitlist/count?list=instant')
            ->assertNotFound()
            ->assertJson(['error' => 'count_disabled']);
    });

    it('refuses a list name no signup could post, as the signup does', function (mixed $list) {
        $this->getJson('/waitlist/count?'.http_build_query(['list' => $list]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('list');
    })->with([
        'a pattern break' => ['bad name'],
        'too long' => [str_repeat('a', 101)],
        'an array' => [['beta']],
    ]);

    it('caches nothing for a list below the threshold', function () {
        confirmedSignups(3);
        Waitlist::for('beta')->publicCount();

        expect(Cache::get('waitlist:public-count:'.hash('sha256', json_encode(['default', 'beta']))))->toBeNull();

        confirmedSignups(10, prefix: 'more');
        Waitlist::for('beta')->publicCount();

        expect(Cache::get('waitlist:public-count:'.hash('sha256', json_encode(['default', 'beta']))))->toBe(['count' => 10]);
    });

    it('stays inside the project the request acts for', function () {
        confirmedSignups(10);
        confirmedSignups(5, project: 'acme', prefix: 'acme');

        expect($this->getJson('/waitlist/count?list=beta', ['X-Waitlist-Project' => 'acme'])->assertOk()->json('data.count'))->toBeNull()
            ->and($this->getJson('/waitlist/count?list=beta')->json('data.count'))->toBe(10);
    });

    it('takes the default list when none is named', function () {
        config()->set(ConfigKey::DefaultList->value, 'beta');
        confirmedSignups(10);

        $this->getJson('/waitlist/count')->assertOk()->assertJson(['data' => ['list' => 'beta', 'count' => 10]]);
    });

    it('asks the useWaitlist gate for ViewCount before it reads the list', function () {
        $asked = [];
        Gate::define('useWaitlist', function (?object $caller, string $project, WaitlistAction $action, ?string $list = null) use (&$asked) {
            $asked[] = [$project, $action, $list];

            return Response::denyWithStatus(401);
        });

        $this->getJson('/waitlist/count?list=nope')->assertUnauthorized();

        expect($asked)->toBe([['default', WaitlistAction::ViewCount, 'nope']]);
    });

    it('refuses a website the project does not list', function () {
        $this->getJson('/waitlist/count?list=beta', ['X-Waitlist-Project' => 'acme', 'Origin' => 'https://evil.example'])
            ->assertForbidden();

        $this->getJson('/waitlist/count?list=beta', ['X-Waitlist-Project' => 'acme', 'Origin' => 'https://acme.example'])
            ->assertOk();
    });

    it('is in the signup group, with its limiter', function () {
        expect(Route::getRoutes()->getByName('waitlist.count')->gatherMiddleware())->toContain('throttle:waitlist');
    });

    it('is the same number from PHP, whether or not the list publishes it', function () {
        confirmedSignups(13, 'quiet');

        expect(Waitlist::for('quiet')->publicCount())->toBe(10)
            ->and(Waitlist::for('beta')->publicCount())->toBeNull();
    });
});

describe('the position', function () {
    it('is off unless the list publishes it', function () {
        [$token] = confirmedSignups(1, 'quiet');

        $this->getJson("/waitlist/unsubscribe/{$token}/position")
            ->assertNotFound()
            ->assertJson(['error' => 'position_disabled']);
    });

    it('answers invalid_token for a token that belongs to nobody', function () {
        $this->getJson('/waitlist/unsubscribe/'.str_repeat('a', 64).'/position')
            ->assertNotFound()
            ->assertJson(['error' => 'invalid_token']);
    });

    it('follows the order of confirmation, and moves up when someone ahead leaves', function () {
        [$first, $second, $third] = confirmedSignups(3);

        expect([positionOf($first), positionOf($second), positionOf($third)])->toBe([1, 2, 3]);

        Waitlist::unsubscribe($second);
        $this->travel(61)->seconds();

        expect([positionOf($first), positionOf($second), positionOf($third)])->toBe([1, null, 2]);

        $this->travel(61)->seconds();
        Waitlist::confirm(subscribeAndCapture('beta', 'user2@example.com')['confirm']);

        expect([positionOf($first), positionOf($second), positionOf($third)])->toBe([1, 3, 2]);
    });

    it('is cached for a minute, but never past leaving or coming back', function () {
        [$first, $second] = confirmedSignups(2);
        expect(positionOf($second))->toBe(2);

        Waitlist::unsubscribe($first);
        expect(positionOf($second))->toBe(2);

        $this->travel(61)->seconds();
        expect(positionOf($second))->toBe(1);

        Waitlist::unsubscribe($second);
        expect(positionOf($second))->toBeNull();

        $this->travel(1)->seconds();
        Waitlist::confirm(subscribeAndCapture('beta', 'user2@example.com')['confirm']);
        expect(positionOf($second))->toBe(1);
    });

    it('answers with the entry and no one else\'s data', function () {
        [$token] = confirmedSignups(2);

        $this->getJson("/waitlist/unsubscribe/{$token}/position")
            ->assertOk()
            ->assertExactJson(['data' => ['project' => 'default', 'list' => 'beta', 'status' => 'confirmed', 'position' => 1]]);
    });

    it('is null while the person has not confirmed', function () {
        $token = subscribeAndCapture('beta', 'waiting@example.com')['unsubscribe'];

        $this->getJson("/waitlist/unsubscribe/{$token}/position")
            ->assertOk()
            ->assertJson(['data' => ['status' => 'pending', 'position' => null]]);
    });

    it('counts neither waiting addresses, nor other lists, nor other projects', function () {
        confirmedSignups(2, 'quiet', prefix: 'quiet');
        confirmedSignups(2, project: 'acme', prefix: 'acme');
        subscribeAndCapture('beta', 'waiting@example.com');
        [$token] = confirmedSignups(1);

        expect(positionOf($token))->toBe(1);
    });

    it('breaks a tie within one second by the cycle that started first', function () {
        $this->freezeSecond();

        $a = subscribeAndCapture('beta', 'a@example.com');
        $b = subscribeAndCapture('beta', 'b@example.com');
        Waitlist::confirm($b['confirm']);
        Waitlist::confirm($a['confirm']);
        [$first, $second] = [$a['unsubscribe'], $b['unsubscribe']];

        expect([positionOf($first), positionOf($second)])->toBe([1, 2]);
    });

    it('takes the project and list from the token\'s entry', function () {
        Waitlist::define('acme', function (ProjectDefinition $project): void {
            TestCase::defineTestPurposes($project);
            $project->list('beta', purpose: 'waitlist')->doubleOptIn();
        });
        [$acme] = confirmedSignups(1, project: 'acme', prefix: 'acme');

        // The default project's beta publishes positions; acme's does not.
        $this->getJson("/waitlist/unsubscribe/{$acme}/position", ['X-Waitlist-Project' => 'default'])
            ->assertNotFound()
            ->assertJson(['error' => 'position_disabled']);
    });

    it('changes nothing', function () {
        [$token] = confirmedSignups(1);
        $activity = WaitlistActivity::query()->count();
        $updated = WaitlistEntry::query()->value('updated_at');

        positionOf($token);

        expect(WaitlistActivity::query()->count())->toBe($activity)
            ->and(WaitlistEntry::query()->value('updated_at'))->toEqual($updated);
    });

    it('is off on a list without double opt-in, where a typed-in address would move it', function () {
        [$token] = confirmedSignups(1, 'instant');

        $this->getJson("/waitlist/unsubscribe/{$token}/position")
            ->assertNotFound()
            ->assertJson(['error' => 'position_disabled']);
    });

    it('answers invalid_token once the entry was erased', function () {
        [$token] = confirmedSignups(1);
        Waitlist::for('beta')->forget('user1@example.com');

        $this->getJson("/waitlist/unsubscribe/{$token}/position")->assertNotFound()->assertJson(['error' => 'invalid_token']);
    });

    it('is in the links group, with its limiter', function () {
        expect(Route::getRoutes()->getByName('waitlist.unsubscribe.position')->gatherMiddleware())->toContain('throttle:waitlist-links');
    });

    it('is the same from PHP, null for an entry of another list', function () {
        confirmedSignups(2, 'quiet');
        [$first, $second] = confirmedSignups(2);
        $entry = Waitlist::findByUnsubscribeToken($second);

        expect(Waitlist::for('beta')->position($entry))->toBe(2)
            ->and(Waitlist::for('quiet')->position($entry))->toBeNull()
            ->and(Waitlist::project('acme')->for('beta')->position($entry))->toBeNull();

        Waitlist::for('beta')->forget('user2@example.com');

        expect(Waitlist::for('beta')->position($entry))->toBeNull();
    });
});

it('keeps both switches on the list policy, also when its optional purposes change', function () {
    $policy = app(ProjectCatalog::class)->policy('default', 'beta');

    expect($policy->publicCount)->toBeTrue()
        ->and($policy->publicPosition)->toBeTrue()
        ->and($policy->withOptional(['newsletter'])->publicCount)->toBeTrue()
        ->and($policy->withOptional(['newsletter'])->publicPosition)->toBeTrue()
        ->and(app(ProjectCatalog::class)->policy('default', 'quiet')->publicCount)->toBeFalse();
});
