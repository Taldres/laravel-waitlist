<?php

declare(strict_types=1);

use App\Providers\SeveralProjectsWaitlistServiceProvider;
use Illuminate\Support\Facades\File;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\WaitlistServiceProvider;

// Own directory per test: the Testbench skeleton is shared by parallel
// processes, which would read half-published files.
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/waitlist-publish-'.bin2hex(random_bytes(4));

    // Laravel finds the app namespace by matching composer.json against the
    // app path, so it has to be known before that path moves.
    $this->app->getNamespace();

    $this->app->useConfigPath($this->sandbox.'/config');
    $this->app->useDatabasePath($this->sandbox.'/database');
    $this->app->useAppPath($this->sandbox.'/app');
    $this->app->useBootstrapPath($this->sandbox.'/bootstrap');

    File::ensureDirectoryExists($this->sandbox.'/bootstrap');
    File::put($this->sandbox.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");

    (new WaitlistServiceProvider($this->app))->boot();
});

afterEach(fn () => File::deleteDirectory($this->sandbox));

it('publishes the migrations in the order their foreign keys need', function () {
    $this->artisan('vendor:publish', ['--tag' => 'waitlist-migrations'])->assertSuccessful();

    $published = array_map(
        fn (string $path): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)),
        File::glob($this->sandbox.'/database/migrations/*.php'),
    );

    expect($published)->toBe([
        'create_waitlist_entries_table.php',
        'create_waitlist_subscriptions_table.php',
        'create_waitlist_consents_table.php',
        'create_waitlist_activity_table.php',
        'create_waitlist_wordings_table.php',
        'add_reconfirmation_to_waitlist_subscriptions_table.php',
    ]);
});

it('publishes the config', function () {
    $this->artisan('vendor:publish', ['--tag' => 'waitlist-config'])->assertSuccessful();

    expect(File::exists($this->sandbox.'/config/waitlist.php'))->toBeTrue();
});

it('publishes the service provider', function () {
    $this->artisan('vendor:publish', ['--tag' => 'waitlist-provider'])->assertSuccessful();

    expect(File::get($this->sandbox.'/app/Providers/WaitlistServiceProvider.php'))
        ->toContain('namespace App\Providers;')
        ->toContain('class WaitlistServiceProvider extends ServiceProvider')
        ->toContain('Waitlist::define(function (ProjectDefinition $project): void {');
});

it('publishes a provider that defines a working default project as it is', function () {
    $this->artisan('vendor:publish', ['--tag' => 'waitlist-provider'])->assertSuccessful();

    require $this->sandbox.'/app/Providers/WaitlistServiceProvider.php';

    $this->app->register(App\Providers\WaitlistServiceProvider::class);

    expect(Waitlist::purposes('default')[0]->text)->toBe('Email me when early access opens. I can unsubscribe at any time.')
        ->and(Waitlist::for('default')->add('user@example.com', ['waitlist' => '2026-10'])->entry->list)->toBe('default')
        ->and(Waitlist::for('default')->fields())->toBe([]);
});

it('publishes a provider for several projects that works as it is, apart from the routes', function () {
    $this->artisan('waitlist:install', ['--projects' => true])->assertSuccessful();

    // Renamed: the test above declares App\Providers\WaitlistServiceProvider
    // in the same process.
    $provider = $this->sandbox.'/app/Providers/WaitlistServiceProvider.php';
    File::put($provider, str_replace('class WaitlistServiceProvider', 'class SeveralProjectsWaitlistServiceProvider', File::get($provider)));

    require $provider;

    $this->app->register(SeveralProjectsWaitlistServiceProvider::class);

    expect(app(ProjectCatalog::class)->urlPattern('default', Page::Invalid->value))->toBe('https://example.com/waitlist/oops')
        ->and(Waitlist::project('rocket')->purposes('default')[0]->text)->toBe('Email me when Rocket launches. I can unsubscribe at any time.')
        ->and(Waitlist::project('rocket')->for('default')->add('user@example.com', waitlistConsent())->entry->project)->toBe('rocket')
        ->and(Waitlist::project('anvil')->for('default')->add('user@example.com', waitlistConsent())->entry->project)->toBe('anvil')
        ->and(fn () => Waitlist::for('default')->add('user@example.com', waitlistConsent()))->toThrow(UnknownWaitlistException::class);

    // What the install output warns about: without a resolver, the routes act
    // for the default project, which has no lists.
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('list');
});

describe('waitlist:install', function () {
    it('publishes the provider, the config and the migrations, and registers the provider', function () {
        $this->artisan('waitlist:install')
            ->expectsOutputToContain('Describe your waitlist in app/Providers/WaitlistServiceProvider.php, then run php artisan migrate.')
            ->doesntExpectOutputToContain('The setup is incomplete')
            ->assertSuccessful();

        expect(File::exists($this->sandbox.'/app/Providers/WaitlistServiceProvider.php'))->toBeTrue()
            ->and(File::exists($this->sandbox.'/config/waitlist.php'))->toBeTrue()
            ->and(File::glob($this->sandbox.'/database/migrations/*_create_waitlist_entries_table.php'))->toHaveCount(1)
            ->and(require $this->sandbox.'/bootstrap/providers.php')->toBe([
                'App\Providers\AppServiceProvider',
                'App\Providers\WaitlistServiceProvider',
            ]);
    });

    it('can run again without registering the provider twice or overwriting your definitions', function () {
        $this->artisan('waitlist:install')->assertSuccessful();

        $provider = $this->sandbox.'/app/Providers/WaitlistServiceProvider.php';
        File::put($provider, str_replace("'waitlist'", "'launch'", File::get($provider)));

        $this->artisan('waitlist:install')->assertSuccessful();

        expect(File::get($provider))->toContain("'launch'")
            ->and(require $this->sandbox.'/bootstrap/providers.php')->toBe([
                'App\Providers\AppServiceProvider',
                'App\Providers\WaitlistServiceProvider',
            ]);
    });

    it('starts with several projects with --projects and says the setup is incomplete', function () {
        $this->artisan('waitlist:install', ['--projects' => true])
            ->expectsOutputToContain('Describe your projects in app/Providers/WaitlistServiceProvider.php, then run php artisan migrate.')
            ->expectsOutputToContain('The setup is incomplete until you bind a ProjectResolver in that provider.')
            ->assertSuccessful();

        expect(File::get($this->sandbox.'/app/Providers/WaitlistServiceProvider.php'))->toBe(File::get(dirname(__DIR__, 2).'/stubs/WaitlistServiceProvider.projects.stub'))
            ->and(File::exists($this->sandbox.'/config/waitlist.php'))->toBeTrue()
            ->and(File::glob($this->sandbox.'/database/migrations/*_create_waitlist_entries_table.php'))->toHaveCount(1)
            ->and(require $this->sandbox.'/bootstrap/providers.php')->toBe([
                'App\Providers\AppServiceProvider',
                'App\Providers\WaitlistServiceProvider',
            ]);
    });

    it('fails with --projects when the provider cannot be written, before publishing anything else', function () {
        File::partialMock()->shouldReceive('copy')->andReturnFalse();

        $this->artisan('waitlist:install', ['--projects' => true])
            ->expectsOutputToContain('Could not write app/Providers/WaitlistServiceProvider.php')
            ->doesntExpectOutputToContain('The setup is incomplete')
            ->assertFailed();

        expect(File::exists($this->sandbox.'/config/waitlist.php'))->toBeFalse()
            ->and(require $this->sandbox.'/bootstrap/providers.php')->toBe(['App\Providers\AppServiceProvider']);
    });

    it('leaves a published provider alone with --projects and says so', function () {
        $this->artisan('waitlist:install')->assertSuccessful();

        $provider = $this->sandbox.'/app/Providers/WaitlistServiceProvider.php';
        $before = File::get($provider);

        $this->artisan('waitlist:install', ['--projects' => true])
            ->expectsOutputToContain('app/Providers/WaitlistServiceProvider.php already exists and was left as it is.')
            ->doesntExpectOutputToContain('The setup is incomplete')
            ->assertSuccessful();

        expect(File::get($provider))->toBe($before)
            ->and(require $this->sandbox.'/bootstrap/providers.php')->toBe([
                'App\Providers\AppServiceProvider',
                'App\Providers\WaitlistServiceProvider',
            ]);
    });

    it('keeps an application namespace of its own', function (array $options) {
        (fn () => $this->namespace = 'Acme\\')->call($this->app);

        $this->artisan('waitlist:install', $options)->assertSuccessful();

        expect(File::get($this->sandbox.'/app/Providers/WaitlistServiceProvider.php'))->toContain('namespace Acme\Providers;')
            ->and(require $this->sandbox.'/bootstrap/providers.php')->toContain('Acme\Providers\WaitlistServiceProvider');
    })->with([
        'one project' => [[]],
        'several projects' => [['--projects' => true]],
    ]);

    it('asks you to register the provider when there is no providers file', function () {
        File::delete($this->sandbox.'/bootstrap/providers.php');

        $this->artisan('waitlist:install')
            ->expectsOutputToContain("Register App\Providers\WaitlistServiceProvider in your application's providers yourself.")
            ->assertSuccessful();
    });
});
