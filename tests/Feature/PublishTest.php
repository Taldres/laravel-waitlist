<?php

declare(strict_types=1);

use App\Listeners\SendWaitlistConfirmationMail;
use App\Mail\ConfirmWaitlistMail;
use App\Providers\SeveralProjectsWaitlistServiceProvider;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
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
        'widen_waitlist_wordings_table.php',
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
    enableWaitlistRoutes();

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

// The classes are declared once per process; every test writes the same code.
function loadScaffoldedMail(string $sandbox): void
{
    if (! class_exists(ConfirmWaitlistMail::class, false)) {
        require "{$sandbox}/app/Mail/ConfirmWaitlistMail.php";
        require "{$sandbox}/app/Listeners/SendWaitlistConfirmationMail.php";
    }
}

function scaffoldedMailUrls(): void
{
    defineDefaultProject(fn ($project) => $project->urls(
        confirm: 'https://example.com/confirm/{token}',
        unsubscribe: 'https://example.com/leave/{token}',
    ));
}

describe('waitlist:install --mail', function () {
    beforeEach(function () {
        // The skeleton's resource path follows the base path only.
        $this->app->setBasePath($this->sandbox);
        $this->mailFiles = [
            'app/Mail/ConfirmWaitlistMail.php',
            'app/Listeners/SendWaitlistConfirmationMail.php',
            'resources/views/mail/waitlist/confirm.blade.php',
        ];
    });

    it('writes the mailable, the listener and the view into the app', function () {
        $this->artisan('waitlist:install', ['--mail' => true])
            ->expectsOutputToContain('The confirmation mail is yours now')
            ->assertSuccessful();

        foreach ($this->mailFiles as $file) {
            expect(File::exists("{$this->sandbox}/{$file}"))->toBeTrue($file);
        }

        foreach (['app/Mail/ConfirmWaitlistMail.php', 'app/Listeners/SendWaitlistConfirmationMail.php'] as $file) {
            $code = File::get("{$this->sandbox}/{$file}");

            expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class)
                ->and($code)->toContain('namespace App\\')->not->toContain('{{ namespace }}');
        }
    });

    it('writes nothing without --mail', function () {
        $this->artisan('waitlist:install')->assertSuccessful();

        foreach ($this->mailFiles as $file) {
            expect(File::exists("{$this->sandbox}/{$file}"))->toBeFalse($file);
        }
    });

    it('leaves files that exist as they are, also on a second run', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();

        $mailable = "{$this->sandbox}/app/Mail/ConfirmWaitlistMail.php";
        File::put($mailable, str_replace('Please confirm your spot', 'Welcome aboard', File::get($mailable)));
        File::delete("{$this->sandbox}/resources/views/mail/waitlist/confirm.blade.php");

        $this->artisan('waitlist:install', ['--mail' => true])
            ->expectsOutputToContain('app/Mail/ConfirmWaitlistMail.php already exists and was left as it is.')
            ->assertSuccessful();

        expect(File::get($mailable))->toContain('Welcome aboard')
            ->and(File::exists("{$this->sandbox}/resources/views/mail/waitlist/confirm.blade.php"))->toBeTrue();
    });

    it('keeps an application namespace of its own', function () {
        (fn () => $this->namespace = 'Acme\\')->call($this->app);

        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();

        expect(File::get("{$this->sandbox}/app/Listeners/SendWaitlistConfirmationMail.php"))
            ->toContain('namespace Acme\Listeners;')
            ->toContain('use Acme\Mail\ConfirmWaitlistMail;')
            ->and(File::get("{$this->sandbox}/app/Mail/ConfirmWaitlistMail.php"))->toContain('namespace Acme\Mail;');
    });

    it('mails the registered wording and the confirm link, and reports the mail', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();

        loadScaffoldedMail($this->sandbox);

        $this->app['view']->addLocation("{$this->sandbox}/resources/views");
        config()->set(ConfigKey::DoubleOptIn->value, true);
        defineDefaultProject(fn ($project) => $project->urls(
            confirm: 'https://example.com/confirm/{token}',
            unsubscribe: 'https://example.com/leave/{token}',
        ));
        Mail::fake();
        Event::listen(EntrySubscribed::class, SendWaitlistConfirmationMail::class);

        Waitlist::for('beta')->add('user@example.com', waitlistConsent());

        Mail::assertSent(ConfirmWaitlistMail::class, fn (ConfirmWaitlistMail $mail): bool => $mail->hasTo('user@example.com')
            && str_starts_with($mail->confirmUrl, 'https://example.com/confirm/')
            && $mail->agreedTo === ['Email me when early access opens.']);
        Mail::assertSent(ConfirmWaitlistMail::class, function (ConfirmWaitlistMail $mail): bool {
            $mail->assertSeeInHtml('Email me when early access opens.');
            $mail->assertSeeInHtml($mail->confirmUrl, false);

            return true;
        });
        expect(activityTypes())->toContain(ActivityType::ConfirmationMailed)
            ->and(WaitlistActivity::query()->where('type', ActivityType::ConfirmationMailed)->sole()->reference)->toBe('confirm-mail@1');
    });

    it('lists only the purposes still held when a pending request is resent', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();
        loadScaffoldedMail($this->sandbox);

        config()->set(ConfigKey::DoubleOptIn->value, true);
        config()->set(ConfigKey::ResendCooldown->value, null);
        scaffoldedMailUrls();
        Mail::fake();
        Event::listen(EntrySubscribed::class, SendWaitlistConfirmationMail::class);

        Waitlist::for('beta')->add('user@example.com', ['waitlist' => '2026-10', 'newsletter' => '2026-10']);
        Waitlist::for('beta')->withdraw('user@example.com', 'newsletter');
        Waitlist::for('beta')->resendConfirmation('user@example.com');

        $agreed = [];
        Mail::assertSent(ConfirmWaitlistMail::class, function (ConfirmWaitlistMail $mail) use (&$agreed): bool {
            $agreed[] = $mail->agreedTo;

            return true;
        });

        expect($agreed)->toBe([
            ['Email me when early access opens.', 'Also send me the newsletter.'],
            ['Email me when early access opens.'],
        ]);
    });

    it('reports a send that failed, by the exception class, and rethrows it', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();
        loadScaffoldedMail($this->sandbox);

        config()->set(ConfigKey::DoubleOptIn->value, true);
        scaffoldedMailUrls();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Rejected user@example.com'));
        Event::listen(EntrySubscribed::class, SendWaitlistConfirmationMail::class);

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))->toThrow(RuntimeException::class);

        expect(WaitlistActivity::query()->where('type', ActivityType::ConfirmationFailed)->sole()->reference)->toBe('RuntimeException');
    });

    it('does not report a failure that comes after the mail went out', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();
        loadScaffoldedMail($this->sandbox);

        config()->set(ConfigKey::DoubleOptIn->value, true);
        scaffoldedMailUrls();
        Mail::fake();
        Waitlist::partialMock()->shouldReceive('confirmationMailed')->andThrow(new RuntimeException('database gone'));
        Event::listen(EntrySubscribed::class, SendWaitlistConfirmationMail::class);

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))->toThrow(RuntimeException::class);

        Mail::assertSent(ConfirmWaitlistMail::class, 1);
        expect(activityTypes())->not->toContain(ActivityType::ConfirmationFailed);
    });

    it('mails no address that was erased before a retried job ran', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();
        loadScaffoldedMail($this->sandbox);

        config()->set(ConfigKey::DoubleOptIn->value, true);
        scaffoldedMailUrls();
        $requests = captureRequests();
        Waitlist::for('beta')->add('user@example.com', waitlistConsent());
        Waitlist::forget('user@example.com');
        Mail::fake();

        (new SendWaitlistConfirmationMail)->handle($requests[0]);

        Mail::assertNothingSent();
    });

    it('sends nothing for a list without double opt-in', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();
        loadScaffoldedMail($this->sandbox);

        config()->set(ConfigKey::DoubleOptIn->value, false);
        Mail::fake();
        Event::listen(EntrySubscribed::class, SendWaitlistConfirmationMail::class);

        Waitlist::for('beta')->add('user@example.com', waitlistConsent());

        Mail::assertNothingSent();
    });

    it('is found by Laravel event discovery', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();

        loadScaffoldedMail($this->sandbox);

        $discovered = DiscoverEvents::within("{$this->sandbox}/app/Listeners", realpath($this->sandbox));

        expect($discovered)->toBe([EntrySubscribed::class => ['App\Listeners\SendWaitlistConfirmationMail@handle']]);
    });

    it('does not write a second listener when EntrySubscribed already has one', function () {
        Event::listen(EntrySubscribed::class, fn () => null);

        $this->artisan('waitlist:install', ['--mail' => true])
            ->expectsOutputToContain('EntrySubscribed already has a listener')
            ->assertSuccessful();

        expect(File::exists("{$this->sandbox}/app/Listeners/SendWaitlistConfirmationMail.php"))->toBeFalse()
            ->and(File::exists("{$this->sandbox}/app/Mail/ConfirmWaitlistMail.php"))->toBeTrue();
    });

    it('still writes the listener when only a wildcard listener exists', function () {
        Event::listen('*', fn () => null);

        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();

        expect(File::exists("{$this->sandbox}/app/Listeners/SendWaitlistConfirmationMail.php"))->toBeTrue();
    });

    it('fails when a file cannot be written', function () {
        File::partialMock()->shouldReceive('put')->andReturnFalse();

        $this->artisan('waitlist:install', ['--mail' => true])
            ->expectsOutputToContain('Could not write app/Mail/ConfirmWaitlistMail.php.')
            ->doesntExpectOutputToContain('The confirmation mail is yours now')
            ->assertFailed();
    });

    it('does not claim the mail when every file was skipped, and warns about a listener next to an old mailable', function () {
        $this->artisan('waitlist:install', ['--mail' => true])->assertSuccessful();

        $this->artisan('waitlist:install', ['--mail' => true])
            ->doesntExpectOutputToContain('The confirmation mail is yours now')
            ->assertSuccessful();

        File::delete("{$this->sandbox}/app/Listeners/SendWaitlistConfirmationMail.php");

        $this->artisan('waitlist:install', ['--mail' => true])
            ->expectsOutputToContain('check that its constructor takes')
            ->assertSuccessful();
    });
});
