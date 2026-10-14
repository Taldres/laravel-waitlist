<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Taldres\Waitlist\Config\PackageConfig;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\LinkGenerator;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Http\RouteRegistration;
use Taldres\Waitlist\Support\ClosureSpamProtector;
use Taldres\Waitlist\Support\DefaultProjectResolver;
use Taldres\Waitlist\Support\DefinedProjectCatalog;
use Taldres\Waitlist\Support\NullSpamProtector;
use Taldres\Waitlist\Support\PublishableKeyResolver;
use Taldres\Waitlist\Support\SetupAudit;
use Taldres\Waitlist\Tests\Fixtures\ArrayProjectCatalog;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;

beforeEach(function () {
    // An app schedules waitlist:prune; without it the check warns.
    app()->forgetInstance(Schedule::class);
    app(Schedule::class)->command('waitlist:prune')->dailyAt('03:15');
});

function checkOutput(array $arguments = []): array
{
    $exit = Artisan::call('waitlist:check', $arguments);

    return [$exit, Artisan::output()];
}

it('passes where every setting reads and the tables are there', function () {
    [$exit, $output] = checkOutput();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Every setting reads.')
        ->and($output)->toContain('The tables and columns of this version exist.')
        ->and($output)->not->toContain('is not a setting the package reads');
});

it('hints while no scheduled task runs the retention, and does not fail on it even with --strict', function () {
    app()->forgetInstance(Schedule::class);

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(0)
        ->and($output)->toContain("No scheduled task runs waitlist:prune, so the retention periods are not applied. Add Schedule::command('waitlist:prune')->daily() to routes/console.php");

    [$strict, $strictOutput] = checkOutput(['--strict' => true]);

    expect($strict)->toBe(0)
        ->and($strictOutput)->toContain('No scheduled task runs waitlist:prune');

    Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]);

    expect(json_decode(Artisan::output(), true)['laravel_waitlist']['prune_schedule'])->toBe('not scheduled');
});

it('finds package routes that are switched on but not registered, and ones that are registered though switched off', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The signup routes are not registered although routes.enabled is on')
        ->and($output)->toContain('The links routes are not registered although routes.enabled is on');

    require __DIR__.'/../../routes/waitlist.php';
    app('router')->getRoutes()->refreshNameLookups();

    expect(checkOutput()[0])->toBe(0);

    config()->set(ConfigKey::RoutesEnabled->value, false);

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain('routes.enabled is off, but the signup routes are still registered')
        ->and($output)->toContain('routes.enabled is off, but the links routes are still registered');
});

it('leaves the routes alone while the switch does not read, as that is reported on its own', function () {
    config()->set(ConfigKey::RoutesEnabled->value, 'maybe');

    expect(SetupAudit::routeProblems())->toBe([]);
});

it('knows the routes of each group, so the check cannot drift from routes/waitlist.php', function () {
    enableWaitlistRoutes();
    app('router')->getRoutes()->refreshNameLookups();

    $registered = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'waitlist.'))
        ->map(fn (string $name): string => substr($name, strlen('waitlist.')))
        ->sort()
        ->values()
        ->all();

    expect($registered)->toBe(collect(RouteRegistration::GROUPS)->flatten()->sort()->values()->all());
});

it('names every setting that does not read at once, never its value', function () {
    config()->set(ConfigKey::SignupPerMinute->value, 'secret-value-that-must-not-leak-4242');
    config()->set(ConfigKey::RetentionPendingDays->value, 'soon');
    config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain(ConfigKey::SignupPerMinute->value)
        ->and($output)->toContain(ConfigKey::RetentionPendingDays->value)
        ->and($output)->toContain(ConfigKey::DoubleOptIn->value)
        ->and($output)->not->toContain('secret-value-that-must-not-leak-4242')
        ->and($output)->not->toContain('Every setting reads.');
});

it('checks each setting on its own, also those that are read together', function (array $settings) {
    foreach ($settings as $key) {
        config()->set($key->value, 'soon');
    }

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1);

    foreach ($settings as $key) {
        expect($output)->toContain($key->value);
    }

    expect(count(WaitlistConfig::problems()))->toBe(count($settings));
})->with([
    'the confirm link and the resend cooldown' => [[ConfigKey::ConfirmTokenTtl, ConfigKey::ResendCooldown]],
    'the caps on confirmation requests' => [[ConfigKey::MaxConfirmations, ConfigKey::MaxPendingPerAddress]],
    'the manage link and its cooldown' => [[ConfigKey::ManageTokenTtl, ConfigKey::ManageRequestCooldown]],
    'the IP and the user agent' => [[ConfigKey::StoreIp, ConfigKey::StoreUserAgent]],
    'the three retention periods' => [[ConfigKey::RetentionPendingDays, ConfigKey::RetentionUnsubscribedDays, ConfigKey::RetentionRequestMetadataDays]],
    'the export' => [[ConfigKey::ExportSpreadsheetSafe, ConfigKey::ExportColumns]],
]);

it('warns when the database cannot be read, and fails on it with --strict', function () {
    config()->set(ConfigKey::Connection->value, 'not-configured');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(0)
        ->and($output)->toContain('The database could not be read')
        ->and($output)->not->toContain('The tables and columns of this version exist.');

    [$strict, $strictOutput] = checkOutput(['--strict' => true]);

    expect($strict)->toBe(1)
        ->and($strictOutput)->toContain('The database could not be read');
});

it('reports a config that is no array once', function () {
    config()->set('waitlist', 'oops');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and(substr_count($output, 'must be an array of settings'))->toBe(1);
});

it('warns of settings in the config that the package does not read', function () {
    config()->set('waitlist.urls', ['confirm' => 'https://example.com/confirm?token={token}']);
    config()->set('waitlist.reporting', ['timezone' => 'Europe/Berlin']);
    config()->set('waitlist.retention.old_days', 7);

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(0)
        ->and($output)->toContain('waitlist.urls is not a setting the package reads')
        ->and($output)->toContain('waitlist.reporting is not a setting')
        ->and($output)->toContain('waitlist.retention.old_days is not a setting')
        ->and($output)->not->toContain('waitlist.urls.confirm');

    [$strict] = checkOutput(['--strict' => true]);

    expect($strict)->toBe(1);
});

it('names the limiters of a config published before, which the routes no longer read', function () {
    $published = require __DIR__.'/../../config/waitlist.php';
    $published['routes']['limiters'] = ['signup' => 'waitlist', 'links' => 'waitlist-links'];
    config()->set('waitlist', PackageConfig::merge($published));

    [$exit, $output] = checkOutput();

    expect(SetupAudit::unknownSettings())->toBe(['waitlist.routes.limiters'])
        ->and($exit)->toBe(0)
        ->and($output)->toContain('waitlist.routes.limiters is not a setting the package reads');
});

/**
 * @return array<string, class-string>
 */
function checkContracts(): array
{
    return [
        'the catalog' => ProjectCatalog::class,
        'the URL generator' => LinkGenerator::class,
        'the project resolver' => ProjectResolver::class,
        'the email normalizer' => EmailNormalizer::class,
        'the spam protector' => SpamProtector::class,
    ];
}

describe('the bindings', function () {
    it('pass the check as the package binds them', function () {
        [$exit, $output] = checkOutput();

        expect($exit)->toBe(0)
            ->and(SetupAudit::bindingProblems())->toBe([])
            ->and($output)->not->toContain('cannot be resolved');
    });

    it('fail the check when one cannot be resolved, naming the contract and why', function (string $contract, Closure $bind, string $reason) {
        $bind($contract);

        [$exit, $output] = checkOutput();

        expect($exit)->toBe(1)
            ->and($output)->toContain("{$contract} cannot be resolved: ")
            ->and($output)->toContain($reason);
    })->with(function () {
        foreach (checkContracts() as $label => $contract) {
            yield "{$label} / a binding that throws" => [$contract, fn (string $contract) => app()->bind($contract, fn () => throw new RuntimeException('The service is down.')), 'The service is down.'];
            yield "{$label} / a class that does not exist" => [$contract, fn (string $contract) => app()->bind($contract, 'App\Waitlist\Typo'), 'Target class [App\Waitlist\Typo] does not exist.'];
        }
    });

    it('fail the check when one resolves to something that does not implement its contract', function (string $contract) {
        app()->bind($contract, fn () => new stdClass);

        [$exit, $output] = checkOutput();

        $expected = "{$contract} resolves to stdClass, which does not implement it.";

        expect($exit)->toBe(1)
            ->and($output)->toContain($contract)
            ->and($output)->toContain($expected);
    })->with(checkContracts());

    it('check the spam protector the endpoints use, and never ask it', function () {
        app()->bind(SpamProtector::class, fn () => throw new RuntimeException('The protector is down.'));
        useSpamCheck(fn (Request $request): never => throw new LogicException('The check must not run.'));

        expect(SetupAudit::bindingProblems())->toBe([]);

        useSpamCheck(null);

        expect(SetupAudit::bindingProblems())->toBe([SpamProtector::class.' cannot be resolved: The protector is down.']);
    });
});

it('lists a group of unknown settings once, and leaves the settings of this version alone', function () {
    config()->set('waitlist.lists', ['beta' => ['purpose' => 'waitlist'], 'launch' => ['purpose' => 'waitlist']]);

    expect(SetupAudit::unknownSettings())->toBe(['waitlist.lists']);
});

it('finds a column that the migrations create and the database lacks', function () {
    Schema::table('waitlist_subscriptions', fn ($table) => $table->dropColumn('confirmation_outcome'));
    Schema::table('waitlist_wordings', fn ($table) => $table->dropColumn('registered_by'));

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The table waitlist_subscriptions lacks the columns confirmation_outcome')
        ->and($output)->toContain('The table waitlist_wordings lacks the columns registered_by')
        ->and(SetupAudit::missingSchema())->toBe(['waitlist_subscriptions' => ['confirmation_outcome'], 'waitlist_wordings' => ['registered_by']]);
});

it('finds a table that does not exist', function () {
    Schema::drop('waitlist_wordings');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The table waitlist_wordings does not exist')
        ->and(SetupAudit::missingSchema())->toBe(['waitlist_wordings' => null]);
});

it('knows the columns the migrations create, so the check cannot drift from them', function (string $kind, string $table) {
    expect(Schema::getColumnListing($table))->toEqualCanonicalizing(SetupAudit::COLUMNS[$kind]);
})->with([
    'entries' => ['entry', 'waitlist_entries'],
    'subscriptions' => ['subscription', 'waitlist_subscriptions'],
    'consents' => ['consent', 'waitlist_consents'],
    'activity' => ['activity', 'waitlist_activity'],
    'wordings' => ['wording', 'waitlist_wordings'],
]);

it('adds a section to about that shows a setting that does not read instead of failing', function () {
    Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]);
    $about = json_decode(Artisan::output(), true)['laravel_waitlist'];

    expect($about['settings'])->toBe('every setting reads')
        ->and($about['routes'])->toBe('off')
        ->and($about['double_opt-in'])->toBe('on')
        ->and($about['retention'])->toBe('unconfirmed 30 days, left 1095 days, request metadata 30 days')
        ->and($about['prune_schedule'])->toBe('15 3 * * *');

    config()->set(ConfigKey::RetentionPendingDays->value, 'soon');
    config()->set(ConfigKey::RoutesEnabled->value, 'true');

    Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]);
    $about = json_decode(Artisan::output(), true)['laravel_waitlist'];

    expect($about['settings'])->toBe('1 do not read, run waitlist:check')
        ->and($about['retention'])->toBe('does not read')
        ->and($about['routes'])->toBe('/waitlist');
});

function aboutSection(): array
{
    expect(Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true)['laravel_waitlist'];
}

class UnreachableProjectCatalog extends DefinedProjectCatalog
{
    public function __construct(ProjectDefinitions $definitions, private readonly Throwable $failure)
    {
        parent::__construct($definitions);
    }

    public function projects(): array
    {
        throw $this->failure;
    }
}

it('shows the implementations the container resolves, as the defaults come out', function () {
    $about = aboutSection();

    expect($about['projects'])->toBe('default')
        ->and($about['catalog'])->toBe(DefinedProjectCatalog::class)
        ->and($about['project_resolver'])->toBe(DefaultProjectResolver::class)
        ->and($about['spam_protector'])->toBe(NullSpamProtector::class);
});

it('names the implementations an app binds instead of the classes in the config', function () {
    app()->bind(ProjectCatalog::class, ArrayProjectCatalog::class);
    app()->bind(ProjectResolver::class, HeaderProjectResolver::class);

    $about = aboutSection();

    expect($about['projects'])->toBe('shop')
        ->and($about['catalog'])->toBe(ArrayProjectCatalog::class)
        ->and($about['project_resolver'])->toBe(HeaderProjectResolver::class);
});

it('names the protector the endpoints use, and the default again once it is cleared', function () {
    useSpamCheck(fn (Request $request): bool => true);

    expect(aboutSection()['spam_protector'])->toBe(ClosureSpamProtector::class);

    useSpamCheck(null);

    expect(aboutSection()['spam_protector'])->toBe(NullSpamProtector::class);
});

it('shows a catalog that cannot list its projects as unavailable, without reporting it, and prints the other rows', function (Throwable $failure) {
    Exceptions::fake();
    app()->bind(ProjectCatalog::class, fn () => new UnreachableProjectCatalog(app(ProjectDefinitions::class), $failure));

    $about = aboutSection();

    expect($about['projects'])->toBe('unavailable')
        ->and($about['catalog'])->toBe(UnreachableProjectCatalog::class)
        ->and($about['settings'])->toBe('every setting reads')
        ->and($about['double_opt-in'])->toBe('on')
        ->and($about['retention'])->toBe('unconfirmed 30 days, left 1095 days, request metadata 30 days');

    Exceptions::assertNothingReported();
})->with([
    'a runtime error' => fn () => new RuntimeException('The catalog is down.'),
    'a database error' => fn () => new QueryException('testing', 'select * from waitlist_projects', [], new PDOException('no such table: waitlist_projects')),
]);

it('tells a setting that does not read from an implementation that is unavailable', function () {
    app()->bind(ProjectResolver::class, fn () => throw new InvalidConfigurationException('The resolver does not read.'));
    app()->bind(SpamProtector::class, fn () => throw new RuntimeException('The protector is down.'));

    $about = aboutSection();

    expect($about['project_resolver'])->toBe('does not read')
        ->and($about['spam_protector'])->toBe('unavailable')
        ->and($about['catalog'])->toBe(DefinedProjectCatalog::class);
});

it('keeps every row apart, those that read no contract included', function () {
    app()->forgetInstance(Schedule::class);
    app()->bind(Schedule::class, fn () => throw new RuntimeException('The scheduler is down.'));

    $about = aboutSection();

    expect($about['prune_schedule'])->toBe('unavailable')
        ->and($about['projects'])->toBe('default')
        ->and($about['double_opt-in'])->toBe('on');
});

describe('publishable keys', function () {
    beforeEach(function () {
        app()->bind(ProjectResolver::class, PublishableKeyResolver::class);

        Waitlist::define('acme', fn (ProjectDefinition $project) => $project->publishableKey('pk_shared'));
        Waitlist::define('rocket', fn (ProjectDefinition $project) => $project->publishableKey('pk_shared'));
        Waitlist::define('anvil', fn (ProjectDefinition $project) => $project->publishableKey('pk_anvil'));
    });

    it('names the projects that share a key, never the key', function () {
        expect(SetupAudit::publishableKeyProblems())->toBe(['The projects [acme], [rocket] share a publishable key, which PublishableKeyResolver refuses; give each its own.']);

        $this->artisan('waitlist:check')->assertFailed()->doesntExpectOutputToContain('pk_shared');
    });

    it('names a definition it cannot build, which would hide a shared key', function () {
        Waitlist::define('rocket', fn () => throw new RuntimeException('The database is down.'));

        expect(SetupAudit::publishableKeyProblems())->toBe(['The definition of the project [rocket] cannot be built, so PublishableKeyResolver cannot reach it by key: The database is down.']);
    });

    it('leaves the keys alone with another resolver, or a subclass that reads them elsewhere', function (Closure $resolver) {
        app()->bind(ProjectResolver::class, $resolver);

        expect(SetupAudit::publishableKeyProblems())->toBe([]);
    })->with([
        'the default resolver' => [fn () => new DefaultProjectResolver],
        'a subclass' => [fn () => new class(app(ProjectDefinitions::class), app(ProjectCatalog::class)) extends PublishableKeyResolver {}],
    ]);
});
