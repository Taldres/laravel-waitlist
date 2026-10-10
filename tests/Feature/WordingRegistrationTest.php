<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Taldres\ImmutableAttributes\Exceptions\ImmutableAttributeException;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\Support\DefinedProjectCatalog;
use Taldres\Waitlist\Support\StoredWordingCatalog;

beforeEach(function () {
    config()->set(ConfigKey::Catalog->value, StoredWordingCatalog::class);

    $this->file = function (array $wording): string {
        $path = tempnam(sys_get_temp_dir(), 'wording');
        file_put_contents($path, json_encode($wording));

        return $path;
    };
});

it('serves wording registered at runtime instead of the definitions', function () {
    expect(Waitlist::registerWording('waitlist', '2026-11', 'Tell me when the beta opens.'))->toBe(1);

    $consent = Waitlist::for('beta')->add('user@example.com', ['waitlist' => '2026-11'])->subscription->consents->sole();

    expect(Waitlist::purposes('beta')[0]->text)->toBe('Tell me when the beta opens.')
        ->and($consent->text)->toBe('Tell me when the beta opens.')
        ->and(fn () => Waitlist::for('beta')->add('other@example.com', ['waitlist' => '2026-10']))->toThrow(UnknownPurposeException::class);
});

it('registers once, refuses other wording for a registered version, and takes new locales later', function () {
    Waitlist::registerWording('waitlist', '2026-11', ['en' => 'Tell me when the beta opens.']);

    expect(Waitlist::registerWording('waitlist', '2026-11', ['en' => 'Tell me when the beta opens.']))->toBe(0)
        ->and(Waitlist::registerWording('waitlist', '2026-11', ['de' => 'Sagt mir, wenn die Beta startet.']))->toBe(1)
        ->and(fn () => Waitlist::registerWording('waitlist', '2026-11', ['en' => 'Tell me when it opens.']))->toThrow(WordingConflictException::class)
        ->and(fn () => Waitlist::registerWording('waitlist', '2026-11', 'One text for all.'))->toThrow(WordingConflictException::class)
        ->and(Waitlist::purposes('beta', locale: 'de')[0]->text)->toBe('Sagt mir, wenn die Beta startet.');
});

it('refuses one text for all locales next to texts per locale, also on first registration', function () {
    expect(fn () => Waitlist::registerWording('waitlist', '2026-11', ['' => 'For everyone.', 'en' => 'For English.']))
        ->toThrow(WordingConflictException::class);

    expect(WaitlistWording::query()->where('version', '2026-11')->exists())->toBeFalse();
});

it('makes the last version registered the current one, and stops accepting a retired one', function () {
    Waitlist::registerWording('waitlist', 'v1', 'First wording.');
    $consent = Waitlist::for('beta')->add('user@example.com', ['waitlist' => 'v1'])->subscription->consents->sole();
    Waitlist::registerWording('waitlist', 'v2', 'Second wording.');

    expect(Waitlist::purposes('beta')[0]->version)->toBe('v2')
        ->and(Waitlist::retireWording('waitlist', 'v1'))->toBe(1)
        ->and(fn () => Waitlist::for('beta')->add('other@example.com', ['waitlist' => 'v1']))->toThrow(UnknownPurposeException::class)
        ->and($consent->fresh()->text)->toBe('First wording.');
});

it('keeps the wording of each project apart', function () {
    Waitlist::define('acme', fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'));

    Waitlist::registerWording('waitlist', 'v1', 'Default wording.');
    Waitlist::project('acme')->registerWording('waitlist', 'v1', 'Acme wording.');

    expect(Waitlist::purposes('beta')[0]->text)->toBe('Default wording.')
        ->and(Waitlist::project('acme')->purposes('beta')[0]->text)->toBe('Acme wording.');
});

it('never rewrites registered wording', function () {
    Waitlist::registerWording('waitlist', 'v1', 'First wording.');

    WaitlistWording::query()->sole()->update(['text' => 'Edited.']);
})->throws(ImmutableAttributeException::class, 'immutable attribute(s) [text]');

it('syncs the wording a frontend ships with on deploy', function () {
    $file = ($this->file)([
        'waitlist' => ['v1' => 'First wording.', 'v2' => ['en' => 'Second wording.', 'de' => 'Zweite Fassung.']],
        'newsletter' => ['v1' => 'Newsletter wording.'],
    ]);

    $this->artisan('waitlist:wording', ['file' => $file])
        ->expectsOutputToContain('Registered 4 wordings, retired 0 versions.')
        ->assertSuccessful();
    $this->artisan('waitlist:wording', ['file' => $file])
        ->expectsOutputToContain('Registered 0 wordings, retired 0 versions.')
        ->assertSuccessful();

    file_put_contents($file, json_encode(['waitlist' => ['v2' => ['en' => 'Second wording.', 'de' => 'Zweite Fassung.']]]));

    $this->artisan('waitlist:wording', ['file' => $file, '--retire-missing' => true])
        ->expectsOutputToContain('Registered 0 wordings, retired 1 versions.')
        ->assertSuccessful();

    unlink($file);

    expect(array_keys(app(StoredWordingCatalog::class)->versions('default', 'waitlist')))->toBe(['v2'])
        ->and(array_keys(app(StoredWordingCatalog::class)->versions('default', 'newsletter')))->toBe(['v1']);
});

it('refuses a purpose without versions instead of retiring all of them', function () {
    Waitlist::registerWording('waitlist', 'v1', 'First wording.');
    $file = ($this->file)(['waitlist' => []]);

    $this->artisan('waitlist:wording', ['file' => $file, '--retire-missing' => true])->assertFailed();

    unlink($file);

    expect(array_keys(app(StoredWordingCatalog::class)->versions('default', 'waitlist')))->toBe(['v1']);
});

it('writes nothing from a file with a conflict', function () {
    Waitlist::registerWording('waitlist', 'v1', 'First wording.');
    $file = ($this->file)(['newsletter' => ['v1' => 'Newsletter wording.'], 'waitlist' => ['v1' => 'Changed wording.']]);

    $this->artisan('waitlist:wording', ['file' => $file])
        ->expectsOutputToContain('The wording of [waitlist] version [v1] is registered with other text')
        ->assertFailed();

    unlink($file);

    expect(WaitlistWording::query()->where('purpose', 'newsletter')->exists())->toBeFalse();
});

it('registers for a project and warns when the catalog does not read registered wording', function () {
    config()->set(ConfigKey::Catalog->value, DefinedProjectCatalog::class);
    $file = ($this->file)(['launch' => ['v1' => 'Tell me when Acme launches.']]);

    $this->artisan('waitlist:wording', ['file' => $file, '--project' => 'acme'])
        ->expectsOutputToContain(ConfigKey::Catalog->value.' does not read registered wording')
        ->assertSuccessful();

    unlink($file);

    expect(WaitlistWording::query()->sole()->project)->toBe('acme');
});

it('refuses names too long to register', function () {
    Waitlist::registerWording('waitlist', str_repeat('v', 101), 'Wording.');
})->throws(InvalidArgumentException::class, 'up to 100 characters');

it('reads the wording of a project in one query per signup', function () {
    Waitlist::registerWording('waitlist', 'v1', 'Waitlist wording.');
    Waitlist::registerWording('newsletter', 'v1', 'Newsletter wording.');

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        $queries += (int) str_contains($query->sql, 'waitlist_wordings');
    });

    Waitlist::for('beta')->add('user@example.com', ['waitlist' => 'v1', 'newsletter' => 'v1']);

    expect($queries)->toBe(1);
});

it('imports the wording from the definitions, so switching to the stored catalog loses nothing', function () {
    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->purpose('launch', ['2026.10' => 'Tell me when Acme launches.']);
        $project->list('beta', purpose: 'launch');
    });

    $this->artisan('waitlist:wording', ['--from-definitions' => true])
        ->expectsOutputToContain('Registered 3 wordings, retired 0 versions.')
        ->assertSuccessful();
    $this->artisan('waitlist:wording', ['--from-definitions' => true, '--project' => 'acme'])
        ->expectsOutputToContain('Registered 1 wordings')
        ->assertSuccessful();

    expect(Waitlist::purposes('beta')[0])->version->toBe('2026-10')->text->toBe('Email me when early access opens.')
        ->and(Waitlist::for('beta')->add('user@example.com', waitlistConsent('2026-09'))->subscription->consents->sole()->text)->toBe('Earlier wording.')
        ->and(Waitlist::project('acme')->purposes('beta')[0]->version)->toBe('2026.10');
});

it('reports a project whose definition holds no wording, and skips purposes that are all retired', function () {
    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->purpose('launch', []);
        $project->list('beta', purpose: 'launch');
    });

    $this->artisan('waitlist:wording', ['--from-definitions' => true, '--project' => 'acme'])
        ->expectsOutputToContain('The definition of [acme] holds no wording.')
        ->assertFailed();
    $this->artisan('waitlist:wording', ['--from-definitions' => true, '--project' => 'nope'])
        ->expectsOutputToContain('The definition of [nope] holds no wording.')
        ->assertFailed();

    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('newsletter', []));

    $this->artisan('waitlist:wording', ['--from-definitions' => true, '--retire-missing' => true])
        ->expectsOutputToContain('Registered 2 wordings, retired 0 versions.')
        ->assertSuccessful();
});

it('needs a file or --from-definitions', function () {
    $this->artisan('waitlist:wording')
        ->expectsOutputToContain('Pass a file or --from-definitions.')
        ->assertFailed();
});
