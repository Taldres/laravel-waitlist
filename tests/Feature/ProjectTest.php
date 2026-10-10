<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\UnknownProjectException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Tests\Fixtures\ArrayProjectCatalog;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;

beforeEach(function () {
    defineProjectTestAcme();
});

/**
 * @param  (Closure(ProjectDefinition): mixed)|null  $extend
 */
function defineProjectTestAcme(?Closure $extend = null): void
{
    Waitlist::define('acme', function (ProjectDefinition $project) use ($extend): void {
        $project->purpose('launch', ['2026-10' => 'Email me when Acme launches.']);
        $project->list('beta', purpose: 'launch');
        $project->urls(confirm: 'https://acme.test/confirm/{token}');

        if ($extend !== null) {
            $extend($project);
        }
    });
}

function acmeConsent(): array
{
    return ['launch' => '2026-10'];
}

it('keeps the same list name apart across projects', function () {
    $default = Waitlist::for('beta')->add('user@example.com', waitlistConsent())->entry;
    $acme = Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent())->entry;

    expect($default->id)->not->toBe($acme->id)
        ->and($default->project)->toBe('default')
        ->and($acme->project)->toBe('acme')
        ->and(Waitlist::project('acme')->for('beta')->count())->toBe(1)
        ->and(Waitlist::for('beta')->count())->toBe(1);

    assertWaitlistInvariants();
});

it('records each project\'s own wording', function () {
    $result = Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent());

    expect($result->subscription->consents[0]->text)->toBe('Email me when Acme launches.')
        ->and(Waitlist::project('acme')->purposes('beta')[0]->purpose)->toBe('launch');
});

it('does not mix up purposes or lists between projects', function (Closure $subscribe, string $exception) {
    expect($subscribe)->toThrow($exception);
})->with([
    'default purpose in acme' => [fn () => Waitlist::project('acme')->for('beta')->add('user@example.com', waitlistConsent()), UnknownPurposeException::class],
    'list acme does not have' => [fn () => Waitlist::project('acme')->for('launch')->add('user@example.com', acmeConsent()), UnknownWaitlistException::class],
    'unknown project' => [fn () => Waitlist::project('nope')->for('beta')->add('user@example.com', waitlistConsent()), UnknownProjectException::class],
]);

it('scopes lookups and erasure to what was asked for', function () {
    Event::fake([EntryForgotten::class]);

    Waitlist::for('beta')->add('user@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent());

    expect(Waitlist::findByEmail('user@example.com'))->toHaveCount(1)
        ->and(Waitlist::allProjects()->findByEmail('user@example.com'))->toHaveCount(2)
        ->and(Waitlist::project('acme')->findByEmail('user@example.com'))->toHaveCount(1)
        ->and(Waitlist::exists('user@example.com', 'beta'))->toBeTrue()
        ->and(Waitlist::project('acme')->exists('user@example.com', 'beta'))->toBeTrue()
        ->and(Waitlist::project('acme')->personalData('user@example.com')->sole()->project)->toBe('acme')
        ->and(Waitlist::personalData('user@example.com')->sole()->project)->toBe('default')
        ->and(Waitlist::allProjects()->personalData('user@example.com'))->toHaveCount(2);

    expect(Waitlist::project('acme')->forget('user@example.com'))->toBe(1)
        ->and(WaitlistEntry::query()->pluck('project')->all())->toBe(['default']);

    Event::assertDispatched(EntryForgotten::class, fn (EntryForgotten $event) => $event->project === 'acme' && $event->list === 'beta');

    expect(Waitlist::forget('user@example.com'))->toBe(1)
        ->and(WaitlistEntry::query()->count())->toBe(0);
});

it('acts on the default project without project(), never on all of them', function () {
    Waitlist::for('beta')->add('user@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent());

    expect(Waitlist::exists('user@example.com'))->toBeTrue()
        ->and(Waitlist::report()->totals()->signups())->toBe(1)
        ->and(Waitlist::forget('user@example.com'))->toBe(1)
        ->and(Waitlist::project('acme')->exists('user@example.com'))->toBeTrue();
});

it('erases across all projects only when asked to', function () {
    Waitlist::for('beta')->add('user@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent());

    expect(Waitlist::allProjects()->forget('user@example.com'))->toBe(2)
        ->and(Waitlist::allProjects()->exists('user@example.com'))->toBeFalse();
});

it('refuses a project the catalog does not know, instead of falling back to the default one', function () {
    expect(fn () => Waitlist::project('acmee'))->toThrow(UnknownProjectException::class, 'The project [acmee] is not configured.')
        ->and(Waitlist::project('acme')->name())->toBe('acme');
});

it('reports and counts per project, also after an erasure', function () {
    Waitlist::for('beta')->add('one@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('one@example.com', acmeConsent());
    Waitlist::project('acme')->for('beta')->add('two@example.com', acmeConsent());

    Waitlist::project('acme')->forget('two@example.com');

    expect(Waitlist::project('acme')->report()->totals()->signups())->toBe(2)
        ->and(Waitlist::project('acme')->for('beta')->report()->totals()->signups())->toBe(2)
        ->and(Waitlist::for('beta')->report()->totals()->signups())->toBe(1)
        ->and(Waitlist::report()->totals()->signups())->toBe(1)
        ->and(Waitlist::allProjects()->report()->totals()->signups())->toBe(3)
        ->and(Waitlist::project('acme')->for('beta')->snapshot()->toArray())->toMatchArray(['project' => 'acme', 'pending' => 1])
        ->and(WaitlistActivity::query()->where('project', 'acme')->whereNull('waitlist_entry_id')->exists())->toBeTrue();
});

it('builds links from the project\'s own URL patterns, never the default project\'s', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(confirm: 'https://default.test/confirm/{token}'));

    $urls = [];
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$urls): void {
        $urls[$event->entry->project] = $event->confirmUrl;
    });

    Waitlist::define('other', function (ProjectDefinition $project): void {
        $project->purpose('launch', ['1' => 'Email me.']);
        $project->list('beta', purpose: 'launch');
    });

    Waitlist::for('beta')->add('user@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent());
    Waitlist::project('other')->for('beta')->add('user@example.com', ['launch' => '1']);

    expect($urls['default'])->toStartWith('https://default.test/confirm/')
        ->and($urls['acme'])->toStartWith('https://acme.test/confirm/')
        ->and($urls['other'])->toBeNull();
});

it('applies a project\'s purposes to grants from the manage link', function () {
    defineProjectTestAcme(function (ProjectDefinition $project): void {
        $project->purpose('updates', ['1' => 'Product updates.']);
        $project->list('beta', purpose: 'launch')->optional('updates');
    });
    config()->set(ConfigKey::DoubleOptIn->value, false);

    $tokens = subscribeAndCapture('beta', 'user@example.com', acmeConsent(), 'acme');

    expect(Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'updates', '1')->purposes)->toBe(['launch', 'updates'])
        ->and($tokens['entry']->fresh()->status)->toBe(EntryStatus::Confirmed);
});

it('takes lists, wording and links from a bound catalog', function () {
    config()->set(ConfigKey::Catalog->value, ArrayProjectCatalog::class);

    $result = Waitlist::project('shop')->for('restock')->add('user@example.com', ['restock' => 'v1']);

    expect($result->entry->status)->toBe(EntryStatus::Confirmed)
        ->and($result->subscription->consents[0]->text)->toBe('Tell me when it is back in stock.')
        ->and(app(ConfirmationUrlGenerator::class)->confirmUrl($result->entry, 'abc'))->toBe('https://shop.test/confirm/abc');

    Artisan::call('waitlist:privacy');

    expect(Artisan::output())->toContain('| shop | restock | restock | yes | v1 | Tell me when it is back in stock. | no |');
});

it('narrows the commands to a project', function () {
    Waitlist::for('beta')->add('user@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('user@example.com', acmeConsent());

    $path = sys_get_temp_dir().'/waitlist-project-'.bin2hex(random_bytes(4)).'.csv';

    try {
        $this->artisan('waitlist:export', ['list' => 'beta', '--project' => 'acme', '--path' => $path])->assertSuccessful();

        expect(substr_count((string) file_get_contents($path), "\n"))->toBe(2);
    } finally {
        @unlink($path);
    }

    $this->artisan('waitlist:forget', ['email' => 'user@example.com', '--project' => 'acme'])->assertSuccessful();

    expect(WaitlistEntry::query()->pluck('project')->all())->toBe(['default'])
        ->and(DB::table('waitlist_entries')->value('project'))->toBe('default');
});

it('stops accepting a version once the catalog leaves it out, and keeps the stored wording', function () {
    $consent = Waitlist::subscribe('beta', 'user@example.com', waitlistConsent('2026-09'))->subscription->consents[0];

    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']));

    expect(fn () => Waitlist::subscribe('beta', 'other@example.com', waitlistConsent('2026-09')))->toThrow(UnknownPurposeException::class)
        ->and($consent->fresh()->text)->toBe('Earlier wording.');
});

describe('over HTTP', function () {
    beforeEach(function () {
        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);
        config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);
        defineProjectTestAcme(fn (ProjectDefinition $project) => $project->urls(
            unsubscribe: 'https://acme.test/leave/{token}',
            confirmed: 'https://acme.test/thanks',
        ));
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(confirmed: 'https://default.test/thanks'));

        require __DIR__.'/../../routes/waitlist.php';
    });

    it('signs up for the project the resolver picks, and serves its wording', function () {
        $this->withHeader('X-Waitlist-Project', 'acme')
            ->getJson('/waitlist/purposes?list=beta')
            ->assertOk()
            ->assertJsonPath('data.0.purpose', 'launch');

        $this->withHeader('X-Waitlist-Project', 'acme')
            ->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => acmeConsent()])
            ->assertStatus(202);

        expect(Waitlist::project('acme')->for('beta')->has('user@example.com'))->toBeTrue()
            ->and(Waitlist::for('beta')->has('user@example.com'))->toBeFalse();
    });

    it('lets the resolver reject a request', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => acmeConsent()])->assertStatus(401);
        $this->withHeader('X-Waitlist-Project', 'nope')->getJson('/waitlist/purposes?list=beta')->assertStatus(401);

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('never falls back to the default project for one the resolver made up', function () {
        app()->bind(ProjectResolver::class, fn () => new class implements ProjectResolver
        {
            public function resolve(Request $request): string
            {
                return 'ghost';
            }
        });

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertStatus(422);
        $this->getJson('/waitlist/purposes?list=beta')->assertNotFound();
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertStatus(202);

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('sends each project\'s browsers to that project\'s pages', function () {
        $acme = subscribeAndCapture('beta', 'user@example.com', acmeConsent(), project: 'acme');
        $default = subscribeAndCapture('beta', 'user@example.com');

        $this->post("/waitlist/confirm/{$acme['confirm']}")->assertRedirect('https://acme.test/thanks');
        $this->post("/waitlist/confirm/{$default['confirm']}")->assertRedirect('https://default.test/thanks');
        $this->get("/waitlist/unsubscribe/{$acme['unsubscribe']}?purpose=launch")
            ->assertRedirect("https://acme.test/leave/{$acme['unsubscribe']}?purpose=launch");
    });
});
