<?php

declare(strict_types=1);

use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\UnknownProjectException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Support\ListPolicy;

describe('defining projects', function () {
    it('defines the default project when no name is given', function () {
        Waitlist::define(function (ProjectDefinition $project): void {
            $project->purpose('launch', ['v1' => 'Email me at launch.']);
            $project->list('launch', purpose: 'launch');
        });

        expect(Waitlist::for('launch')->add('user@example.com', ['launch' => 'v1'])->entry->project)->toBe('default')
            ->and(app(ProjectCatalog::class)->lists('default'))->toBe(['launch']);
    });

    it('defines the default project by its name, too', function () {
        Waitlist::define('default', function (ProjectDefinition $project): void {
            $project->purpose('launch', ['v1' => 'Email me at launch.']);
            $project->list('launch', purpose: 'launch');
        });

        expect(app(ProjectCatalog::class)->lists('default'))->toBe(['launch']);
    });

    it('adds a named project next to the default one', function () {
        Waitlist::define('anvil', function (ProjectDefinition $project): void {
            $project->purpose('launch', ['v1' => 'Email me when Anvil launches.']);
            $project->list('default', purpose: 'launch');
        });

        expect(app(ProjectCatalog::class)->projects())->toBe(['default', 'anvil'])
            ->and(Waitlist::project('anvil')->for('default')->add('user@example.com', ['launch' => 'v1'])->entry->project)->toBe('anvil');
    });

    it('always knows the default project, even before it is defined, and it has no lists then', function () {
        app()->forgetInstance(ProjectDefinitions::class);

        expect(app(ProjectCatalog::class)->projects())->toBe(['default'])
            ->and(app(ProjectCatalog::class)->lists('default'))->toBe([])
            ->and(fn () => Waitlist::for('default')->add('user@example.com', waitlistConsent()))->toThrow(UnknownWaitlistException::class);
    });

    it('replaces a project that is defined again instead of merging the two', function () {
        Waitlist::define(function (ProjectDefinition $project): void {
            $project->purpose('launch', ['v1' => 'Email me at launch.']);
            $project->list('launch', purpose: 'launch');
        });

        expect(app(ProjectCatalog::class)->lists('default'))->toBe(['launch'])
            ->and(app(ProjectCatalog::class)->versions('default', 'waitlist'))->toBe([]);
    });

    it('refuses a project that is not defined instead of falling back to the default one', function () {
        expect(fn () => Waitlist::project('nope'))->toThrow(UnknownProjectException::class, 'The project [nope] is not configured.');
    });

    it('needs a callback', function () {
        Waitlist::define('anvil');
    })->throws(InvalidArgumentException::class, 'Waitlist::define() needs a callback that describes the project [anvil].');

    it('refuses a project name the HTTP layer and the commands could not carry', function (string $name) {
        expect(fn () => Waitlist::define($name, fn (ProjectDefinition $project) => null))
            ->toThrow(InvalidArgumentException::class, "The project name [{$name}]");
    })->with([
        'empty' => [''],
        'space' => ['my project'],
        'leading dot' => ['.hidden'],
        'slash' => ['a/b'],
        'too long' => [str_repeat('a', 101)],
    ]);

    it('chains', function () {
        expect(Waitlist::define(fn (ProjectDefinition $project) => null))->toBe(Waitlist::getFacadeRoot());
    });
});

describe('when the callback runs', function () {
    it('runs the callback on first use, not when it is defined, and only once', function () {
        $runs = 0;

        Waitlist::define('anvil', function (ProjectDefinition $project) use (&$runs): void {
            $runs++;
            $project->purpose('launch', ['v1' => 'Email me when Anvil launches.']);
            $project->list('default', purpose: 'launch');
        });

        expect($runs)->toBe(0);

        Waitlist::project('anvil')->for('default')->add('one@example.com', ['launch' => 'v1']);
        Waitlist::project('anvil')->for('default')->add('two@example.com', ['launch' => 'v1']);
        Waitlist::project('anvil')->purposes('default');

        expect($runs)->toBe(1);
    });

    it('runs the new callback after the project is defined again', function () {
        $catalog = app(ProjectCatalog::class);
        expect($catalog->lists('default'))->toBe(['*']);

        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'), lists: false);

        expect($catalog->lists('default'))->toBe(['beta']);
    });

    it('reports a broken definition where the project is used, and keeps failing until it is fixed', function () {
        Waitlist::define('anvil', function (ProjectDefinition $project): void {
            $project->list('default', purpose: 'launch')->optional('launch');
        });

        $use = fn () => Waitlist::project('anvil')->for('default')->add('user@example.com', ['launch' => 'v1']);

        expect($use)->toThrow(InvalidArgumentException::class, 'The list [anvil/default] lists its primary purpose [launch] as optional too.')
            ->and($use)->toThrow(InvalidArgumentException::class);

        $this->artisan('list')->assertSuccessful();
    });

    it('reads config when the project is first needed, so values set after boot apply', function () {
        config()->set('services.anvil.url', 'https://anvil.test');

        Waitlist::define('anvil', function (ProjectDefinition $project): void {
            $project->purpose('launch', ['v1' => 'Email me when Anvil launches.']);
            $project->list('default', purpose: 'launch');
            $project->urls(confirm: config('services.anvil.url').'/confirm/{token}');
        });

        config()->set('services.anvil.url', 'https://anvil.example');

        expect(app(ProjectCatalog::class)->urlPattern('anvil', Page::Confirm->value))->toBe('https://anvil.example/confirm/{token}');
    });
});

describe('purposes', function () {
    it('keeps the versions in the order they were defined, the last one current', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', [
            '2026-10' => 'Newer first in time.',
            '2026-08' => 'Defined last, so current.',
        ]));

        expect(array_keys(app(ProjectCatalog::class)->versions('default', 'waitlist')))->toBe(['2026-10', '2026-08'])
            ->and(Waitlist::purposes('beta')[0]->version)->toBe('2026-08');
    });

    it('takes a text per locale', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', [
            '2026-10' => ['en' => 'Email me.', 'de' => 'Schreibt mir.'],
        ]));

        expect(Waitlist::purposes('beta', 'de')[0]->text)->toBe('Schreibt mir.');
    });

    it('accepts a purpose whose versions are all retired, and stops offering it', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('newsletter', []));

        expect(app(ProjectCatalog::class)->versions('default', 'newsletter'))->toBe([])
            ->and(array_map(fn ($wording) => $wording->purpose, Waitlist::purposes('beta')))->toBe(['waitlist']);
    });

    it('replaces a purpose that is defined again', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', ['v9' => 'Only this one.']));

        expect(array_keys(app(ProjectCatalog::class)->versions('default', 'waitlist')))->toBe(['v9']);
    });

    it('refuses a malformed wording instead of dropping it from the form', function (mixed $wording) {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', ['v1' => $wording]));

        app(ProjectCatalog::class)->versions('default', 'waitlist');
    })->with([
        'empty text' => [''],
        'a number' => [42],
        'no texts' => [[]],
        'a list instead of locale => text' => [['Email me.']],
        'empty text for a locale' => [['en' => '']],
        'empty locale' => [['' => 'Email me.']],
        'locale too long' => [[str_repeat('x', 36) => 'Email me.']],
        'nested too deep' => [['en' => ['Email me.']]],
    ])->throws(InvalidArgumentException::class, 'The wording of the purpose [waitlist] version [v1] in the project [default] must be a text, or a text per locale.');

    it('refuses purpose names and versions the wording table cannot hold', function (Closure $define, string $message) {
        defineDefaultProject($define);

        expect(fn () => app(ProjectCatalog::class)->versions('default', 'waitlist'))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'empty purpose' => [fn (ProjectDefinition $project) => $project->purpose('', ['v1' => 'Text.']), 'must be named with 1 to 100 characters, [] is not'],
        'long purpose' => [fn (ProjectDefinition $project) => $project->purpose(str_repeat('p', 101), ['v1' => 'Text.']), 'must be named with 1 to 100 characters'],
        'empty version' => [fn (ProjectDefinition $project) => $project->purpose('waitlist', ['' => 'Text.']), 'must be 1 to 100 characters long, [] is not'],
        'long version' => [fn (ProjectDefinition $project) => $project->purpose('waitlist', [str_repeat('v', 101) => 'Text.']), 'must be 1 to 100 characters long'],
    ]);

    it('ignores purposes no list offers', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('unused', ['v1' => 'Never shown.']));

        expect(array_map(fn ($wording) => $wording->purpose, Waitlist::purposes('beta')))->toBe(['waitlist', 'newsletter']);
    });
});

describe('lists', function () {
    it('builds the policy from the list, its optional purposes and its double opt-in', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->list('beta', purpose: 'waitlist')->optional('newsletter')->doubleOptIn(false);
        });

        expect(app(ProjectCatalog::class)->policy('default', 'beta'))->toEqual(new ListPolicy('default', 'beta', 'waitlist', ['newsletter'], false))
            ->and(Waitlist::for('beta')->add('user@example.com', waitlistConsent())->entry->status)->toBe(EntryStatus::Confirmed);
    });

    it('follows the configured double opt-in when the list does not say, read on every use', function () {
        $catalog = app(ProjectCatalog::class);

        expect($catalog->policy('default', 'beta')->doubleOptIn)->toBeTrue();

        config()->set(ConfigKey::DoubleOptIn->value, false);

        expect($catalog->policy('default', 'beta')->doubleOptIn)->toBeFalse();
    });

    it('lets an explicit double opt-in win over a global switch that is off', function () {
        config()->set(ConfigKey::DoubleOptIn->value, false);
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->doubleOptIn());

        expect(Waitlist::for('beta')->add('user@example.com', waitlistConsent())->entry->status)->toBe(EntryStatus::Pending);
    });

    it('collects optional purposes across calls, each once', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->purpose('updates', ['v1' => 'Product updates.']);
            $project->list('beta', purpose: 'waitlist')->optional('newsletter')->optional('updates', 'newsletter');
        });

        expect(app(ProjectCatalog::class)->policy('default', 'beta')->optional)->toBe(['newsletter', 'updates']);
    });

    it('prefers a named list over "*", which takes every other name', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'));

        $catalog = app(ProjectCatalog::class);

        expect($catalog->policy('default', 'beta')->optional)->toBe([])
            ->and($catalog->policy('default', 'anything')->optional)->toBe(['newsletter'])
            ->and($catalog->policy('default', 'anything')->list)->toBe('anything')
            ->and($catalog->lists('default'))->toBe(['*', 'beta']);
    });

    it('accepts only the lists it defines when there is no "*"', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'), lists: false);

        expect(app(ProjectCatalog::class)->policy('default', 'other'))->toBeNull()
            ->and(fn () => Waitlist::for('other')->add('user@example.com', waitlistConsent()))->toThrow(UnknownWaitlistException::class);
    });

    it('replaces a list that is defined again', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->list('beta', purpose: 'waitlist')->optional('newsletter');
            $project->list('beta', purpose: 'waitlist');
        });

        expect(app(ProjectCatalog::class)->policy('default', 'beta')->optional)->toBe([]);
    });

    it('refuses a primary purpose that is optional too', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->optional('waitlist'));

        app(ProjectCatalog::class)->policy('default', 'beta');
    })->throws(InvalidArgumentException::class, 'The list [default/beta] lists its primary purpose [waitlist] as optional too.');

    it('refuses list names a signup could not post', function (string $name) {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list($name, purpose: 'waitlist'));

        expect(fn () => app(ProjectCatalog::class)->lists('default'))
            ->toThrow(InvalidArgumentException::class, "The list name [{$name}] in the project [default] must be at most 100 characters");
    })->with([
        'empty' => [''],
        'space' => ['beta list'],
        'leading dash' => ['-beta'],
        'wildcard inside' => ['beta*'],
        'too long' => [str_repeat('l', 101)],
    ]);

    it('refuses an empty primary or optional purpose', function (Closure $define) {
        defineDefaultProject($define);

        app(ProjectCatalog::class)->lists('default');
    })->with([
        'primary' => [fn (ProjectDefinition $project) => $project->list('beta', purpose: '')],
        'optional' => [fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->optional('')],
    ])->throws(InvalidArgumentException::class, 'must be named with 1 to 100 characters');

    it('still reports a primary purpose without wording when the list is used', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'undefined'));

        Waitlist::for('beta')->add('user@example.com', ['undefined' => 'v1']);
    })->throws(InvalidArgumentException::class, 'The purpose [undefined] of the waitlist [default/beta] has no wording.');
});

describe('pages', function () {
    it('keeps the pattern of every page it is given', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
            confirm: 'https://app.test/confirm/{token}',
            unsubscribe: 'https://app.test/leave/{token}',
            manage: 'https://app.test/preferences/{token}',
            confirmed: 'https://app.test/welcome',
            expired: 'https://app.test/expired',
            invalid: 'https://app.test/oops',
            unsubscribed: 'https://app.test/bye',
            erased: 'https://app.test/gone',
        ));

        $catalog = app(ProjectCatalog::class);

        expect(array_map(fn (Page $page) => $catalog->urlPattern('default', $page->value), [Page::Confirm, Page::Unsubscribe, Page::Manage, Page::Confirmed, Page::Expired, Page::Invalid, Page::Unsubscribed, Page::Erased]))->toBe([
            'https://app.test/confirm/{token}',
            'https://app.test/leave/{token}',
            'https://app.test/preferences/{token}',
            'https://app.test/welcome',
            'https://app.test/expired',
            'https://app.test/oops',
            'https://app.test/bye',
            'https://app.test/gone',
        ]);
    });

    it('adds to the pages of an earlier call, and leaves a page alone for null or an empty string', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->urls(confirm: 'https://app.test/confirm/{token}');
            $project->urls(unsubscribe: 'https://app.test/leave/{token}', confirm: null);
            $project->urls(confirm: '', manage: '');
        });

        $catalog = app(ProjectCatalog::class);

        expect($catalog->urlPattern('default', Page::Confirm->value))->toBe('https://app.test/confirm/{token}')
            ->and($catalog->urlPattern('default', Page::Unsubscribe->value))->toBe('https://app.test/leave/{token}')
            ->and($catalog->urlPattern('default', Page::Manage->value))->toBeNull();
    });

    it('needs {token} in the patterns the mail links use', function (string $action) {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(...[$action => 'https://app.test/'.$action]));

        app(ProjectCatalog::class)->urlPattern('default', $action);
    })->with([Page::Confirm->value, Page::Unsubscribe->value, Page::Manage->value])->throws(InvalidArgumentException::class, 'URL of the project [default] needs a {token} placeholder.');

    it('lets a landing page go without {token}', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(confirmed: 'https://app.test/welcome'));

        expect(app(ProjectCatalog::class)->urlPattern('default', Page::Confirmed->value))->toBe('https://app.test/welcome');
    });

    it('fails on a misspelt page instead of ignoring it', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(...['confrim' => 'https://app.test/confirm/{token}']));

        app(ProjectCatalog::class)->urlPattern('default', Page::Confirm->value);
    })->throws(Error::class, 'Unknown named parameter $confrim');
});

describe('fields', function () {
    it('accepts no fields until some are defined', function () {
        expect(app(ProjectCatalog::class)->fields('default', 'beta'))->toBe([]);
    });

    it('gives every list the project\'s fields, and a list its own on top', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->fields(['source' => 'nullable|string']);
            $project->list('beta', purpose: 'waitlist')->fields(['ref' => ['required']]);
        });

        $catalog = app(ProjectCatalog::class);

        expect($catalog->fields('default', 'beta'))->toBe(['source' => 'nullable|string', 'ref' => ['required']])
            ->and($catalog->fields('default', 'anything'))->toBe(['source' => 'nullable|string']);
    });

    it('lets a list replace a project field of the same name', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->fields(['source' => ['nullable', 'string'], 'ref' => ['nullable']]);
            $project->list('beta', purpose: 'waitlist')->fields(['source' => ['required', 'string']]);
        });

        expect(app(ProjectCatalog::class)->fields('default', 'beta'))->toBe(['source' => ['required', 'string'], 'ref' => ['nullable']]);
    });

    it('gives the fields of "*" only to the lists the project does not name', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->list('*', purpose: 'waitlist')->fields(['source' => ['nullable']]);
            $project->list('beta', purpose: 'waitlist');
        });

        $catalog = app(ProjectCatalog::class);

        expect($catalog->fields('default', 'anything'))->toBe(['source' => ['nullable']])
            ->and($catalog->fields('default', 'beta'))->toBe([]);
    });

    it('has no fields for a list the project does not have', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->fields(['source' => ['nullable']]);
            $project->list('beta', purpose: 'waitlist');
        }, lists: false);

        expect(app(ProjectCatalog::class)->fields('default', 'other'))->toBe([])
            ->and(app(ProjectCatalog::class)->fields('nope', 'beta'))->toBe([]);
    });

    it('replaces fields that are set again', function () {
        defineDefaultProject(function (ProjectDefinition $project): void {
            $project->fields(['source' => ['nullable']]);
            $project->fields(['ref' => ['nullable']]);
        });

        expect(app(ProjectCatalog::class)->fields('default', 'beta'))->toBe(['ref' => ['nullable']]);
    });

    it('calls a closure on every read, so its rule objects are built anew', function () {
        $calls = 0;

        defineDefaultProject(function (ProjectDefinition $project) use (&$calls): void {
            $project->fields(function () use (&$calls): array {
                $calls++;

                return ['source' => [new stdClass]];
            });
        });

        $catalog = app(ProjectCatalog::class);
        $first = $catalog->fields('default', 'beta')['source'][0];
        $second = $catalog->fields('default', 'beta')['source'][0];

        expect($calls)->toBe(2)
            ->and($first)->not->toBe($second);
    });

    it('does not call the closure for anything but the fields', function () {
        $calls = 0;

        defineDefaultProject(function (ProjectDefinition $project) use (&$calls): void {
            $project->fields(function () use (&$calls): array {
                $calls++;

                return [];
            });
        });

        Waitlist::for('beta')->add('user@example.com', waitlistConsent());
        Waitlist::purposes('beta');

        expect($calls)->toBe(0);
    });

    it('refuses field names a request could not carry under metadata', function (string|int $field) {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->fields([$field => ['nullable']]));

        app(ProjectCatalog::class)->fields('default', 'beta');
    })->with([
        'dot' => ['address.city'],
        'comma' => ['a,b'],
        'wildcard' => ['*'],
        'space' => ['first name'],
        'leading digit' => ['1st'],
        'leading underscore' => ['_source'],
        'empty' => [''],
        'a list instead of field => rules' => [0],
    ])->throws(InvalidArgumentException::class, 'of the project [default] must start with a letter and contain only letters, digits, "_" and "-".');

    it('names the list whose field is wrong', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->fields(['a.b' => ['nullable']]));

        app(ProjectCatalog::class)->fields('default', 'beta');
    })->throws(InvalidArgumentException::class, 'The field [a.b] of the list [default/beta] must start with a letter');

    it('refuses a closure that does not return field => rules', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(fn () => 'source'));

        app(ProjectCatalog::class)->fields('default', 'beta');
    })->throws(InvalidArgumentException::class, 'The fields of the project [default] must be an array of field => validation rules.');
});
