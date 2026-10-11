<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\ServiceProvider;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\AuthenticatedProjectResolver;
use Taldres\Waitlist\Support\DefaultConfirmationUrlGenerator;
use Taldres\Waitlist\Support\DefaultEmailNormalizer;
use Taldres\Waitlist\Support\DefaultProjectResolver;
use Taldres\Waitlist\Support\DefinedProjectCatalog;
use Taldres\Waitlist\Support\NullSpamProtector;
use Taldres\Waitlist\Support\StoredWordingCatalog;
use Taldres\Waitlist\Tests\TestCase;
use Taldres\Waitlist\WaitlistServiceProvider;

/**
 * Leaves out a tag after a plus, so that ada+news@ and ada@ are one address.
 */
class BindingsPlusNormalizer implements EmailNormalizer
{
    public function normalize(string $email): string
    {
        return (string) preg_replace('/\+[^@]*@/', '@', mb_strtolower(trim($email)));
    }
}

class BindingsUrlGenerator extends DefaultConfirmationUrlGenerator {}

class BindingsCatalog extends DefinedProjectCatalog {}

class BindingsResolver extends DefaultProjectResolver {}

class BindingsDenyAllProtector implements SpamProtector
{
    public function passes(Request $request): bool
    {
        return false;
    }
}

/**
 * Each contract with the package's default and an implementation of the app.
 *
 * @return array<string, array{0: class-string, 1: class-string, 2: class-string}>
 */
function bindingsContracts(): array
{
    return [
        'the email normalizer' => [EmailNormalizer::class, DefaultEmailNormalizer::class, BindingsPlusNormalizer::class],
        'the URL generator' => [ConfirmationUrlGenerator::class, DefaultConfirmationUrlGenerator::class, BindingsUrlGenerator::class],
        'the catalog' => [ProjectCatalog::class, DefinedProjectCatalog::class, BindingsCatalog::class],
        'the project resolver' => [ProjectResolver::class, DefaultProjectResolver::class, BindingsResolver::class],
        'the spam protector' => [SpamProtector::class, NullSpamProtector::class, BindingsDenyAllProtector::class],
    ];
}

/**
 * Registers a provider of the app's, which Laravel does after the package's.
 *
 * @param  Closure(Application): void  $register
 */
function bindingsRegisterAppProvider(Closure $register): void
{
    app()->register(new class(app(), $register) extends ServiceProvider
    {
        /**
         * @param  Closure(Application): void  $callback
         */
        public function __construct(Application $app, private readonly Closure $callback)
        {
            parent::__construct($app);
        }

        public function register(): void
        {
            ($this->callback)($this->app);
        }
    }, force: true);
}

/**
 * The lookup hash of an address in its normalized form, under APP_KEY.
 */
function bindingsLookupHash(string $normalized): string
{
    $subkey = hash_hmac('sha256', 'laravel-waitlist:email', base64_decode(substr((string) config('app.key'), 7)), true);

    return hash_hmac('sha256', $normalized, $subkey);
}

function bindingsNewsletterWithdrawn(string $project, string $list): bool
{
    return WaitlistConsent::query()
        ->where('purpose', 'newsletter')
        ->whereNotNull('withdrawn_at')
        ->whereHas('subscription.entry', fn ($entry) => $entry->where('project', $project)->where('list', $list))
        ->exists();
}

describe('the defaults', function () {
    it('are bound without any config at all', function (string $contract, string $default) {
        config()->offsetUnset('waitlist');

        expect(app($contract)::class)->toBe($default);
    })->with(bindingsContracts());

    it('are no longer named in config/waitlist.php', function () {
        expect(require __DIR__.'/../../config/waitlist.php')
            ->not->toHaveKeys(['catalog', 'url_generator', 'project_resolver', 'email_normalizer', 'spam_protector']);
    });

    it('resolve the default class through the container, so the app can decorate it', function () {
        $decorator = new class implements EmailNormalizer
        {
            public function normalize(string $email): string
            {
                return strtolower($email);
            }
        };
        app()->bind(DefaultEmailNormalizer::class, fn () => $decorator);

        expect(app(EmailNormalizer::class))->toBe($decorator);
    });
});

describe('a binding of the app', function () {
    it('replaces the default when a provider that registers after the package binds it', function (string $contract, string $default, string $mine) {
        bindingsRegisterAppProvider(fn (Application $app) => $app->bind($contract, $mine));

        expect(app($contract)::class)->toBe($mine);
    })->with(bindingsContracts());

    it('is kept when the app bound it before the package registers', function (string $contract, string $default, string $mine) {
        app()->bind($contract, $mine);

        (new WaitlistServiceProvider(app()))->register();

        expect(app($contract)::class)->toBe($mine);
    })->with(bindingsContracts());

    it('is skipped when made with bindIf() after the package, which is why the docs say bind()', function (string $contract, string $default, string $mine) {
        bindingsRegisterAppProvider(fn (Application $app) => $app->bindIf($contract, $mine));

        expect(app($contract)::class)->toBe($default);
    })->with(bindingsContracts());

    it('shows in php artisan about as the class the container resolves', function () {
        bindingsRegisterAppProvider(function (Application $app): void {
            $app->bind(ProjectCatalog::class, StoredWordingCatalog::class);
            $app->bind(ProjectResolver::class, BindingsResolver::class);
            $app->bind(SpamProtector::class, BindingsDenyAllProtector::class);
        });

        Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]);
        $about = json_decode(Artisan::output(), true)['laravel_waitlist'];

        expect($about['catalog'])->toBe(StoredWordingCatalog::class)
            ->and($about['project_resolver'])->toBe(BindingsResolver::class)
            ->and($about['spam_protector'])->toBe(BindingsDenyAllProtector::class);
    });
});

/**
 * Each key a binding replaced, with its contract, the class a published config
 * held under it, and an implementation of the app.
 *
 * @return array<string, array{0: string, 1: class-string, 2: class-string, 3: class-string}>
 */
function bindingsReplacedKeys(): array
{
    return [
        'the email normalizer' => ['waitlist.email_normalizer', EmailNormalizer::class, DefaultEmailNormalizer::class, BindingsPlusNormalizer::class],
        'the URL generator' => ['waitlist.url_generator', ConfirmationUrlGenerator::class, DefaultConfirmationUrlGenerator::class, BindingsUrlGenerator::class],
        'the catalog' => ['waitlist.catalog', ProjectCatalog::class, DefinedProjectCatalog::class, BindingsCatalog::class],
        'the project resolver' => ['waitlist.project_resolver', ProjectResolver::class, DefaultProjectResolver::class, BindingsResolver::class],
        'the spam protector' => ['waitlist.spam_protector', SpamProtector::class, NullSpamProtector::class, BindingsDenyAllProtector::class],
    ];
}

function bindingsLeaveEveryKey(): void
{
    foreach (bindingsReplacedKeys() as [$key]) {
        config()->set($key, 'App\\Waitlist\\Mine');
    }
}

describe('a key of an earlier config that a binding replaced', function () {
    it('keeps the default from resolving while it names a class of its own, naming the key and the contract to bind', function (string $key, string $contract) {
        config()->set($key, 'App\\Waitlist\\Mine');

        expect(fn () => app($contract))->toThrow(InvalidConfigurationException::class, "The {$key} config is no longer read: bind {$contract} in a service provider instead");
    })->with(bindingsReplacedKeys());

    it('keeps the default from resolving while it is set to null, as no config accepted that for a class', function (string $key, string $contract) {
        config()->set($key, null);

        expect(fn () => app($contract))->toThrow(InvalidConfigurationException::class, "The {$key} config is no longer read: bind {$contract} in a service provider instead");
    })->with(bindingsReplacedKeys());

    it('fails waitlist:check on a key set to null', function (string $key) {
        config()->set($key, null);

        expect(Artisan::call('waitlist:check'))->toBe(1)
            ->and(Artisan::output())->toContain("The {$key} config is no longer read");
    })->with(bindingsReplacedKeys());

    it('resolves the default while it holds the class waitlist:install published', function (string $key, string $contract, string $default) {
        config()->set($key, $default);

        expect(app($contract)::class)->toBe($default);
    })->with(bindingsReplacedKeys());

    it('leaves a binding of the app alone, made after the package or before it', function (string $key, string $contract, string $default, string $mine) {
        config()->set($key, 'App\\Waitlist\\Mine');
        bindingsRegisterAppProvider(fn (Application $app) => $app->bind($contract, $mine));

        expect(app($contract)::class)->toBe($mine);

        app()->bind($contract, $mine);
        (new WaitlistServiceProvider(app()))->register();

        expect(app($contract)::class)->toBe($mine);
    })->with(bindingsReplacedKeys());

    it('never keeps the app from booting, so artisan still runs to name it', function () {
        bindingsLeaveEveryKey();

        (new WaitlistServiceProvider(app()))->register();
        (new WaitlistServiceProvider(app()))->boot();

        expect(Artisan::call('waitlist:check'))->toBe(1)
            ->and(Artisan::output())->toContain('The waitlist.project_resolver config is no longer read');
    });

    describe('in the flows', function () {
        beforeEach(function () {
            config()->set(ConfigKey::RoutesEnabled->value, true);
            config()->set(ConfigKey::RoutesMiddleware->value, []);

            require __DIR__.'/../../routes/waitlist.php';
        });

        it('refuses a guest whom a resolver of the app would have refused, rather than signing them up for the default project', function () {
            config()->set(ConfigKey::AuthenticationRequired->value, false);
            config()->set('waitlist.project_resolver', AuthenticatedProjectResolver::class);
            Event::fake([ManageLinkRequested::class]);

            $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertServerError();
            $this->getJson('/waitlist/purposes?list=beta')->assertServerError();
            $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertServerError();

            expect(WaitlistEntry::query()->count())->toBe(0);
            Event::assertNotDispatched(ManageLinkRequested::class);
        });

        it('refuses a guest as well when the resolver key is set to null', function () {
            config()->set(ConfigKey::AuthenticationRequired->value, false);
            config()->set('waitlist.project_resolver', null);

            $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertServerError();

            expect(WaitlistEntry::query()->count())->toBe(0);
        });

        it('refuses to sign up, look up or erase by address with a normalizer of the app left in the config, as the lookup hash depends on it', function () {
            $tokens = subscribeAndCapture('beta', 'user@example.com');
            Event::fake([ManageLinkRequested::class]);

            config()->set('waitlist.email_normalizer', 'App\\Waitlist\\GmailAwareNormalizer');

            $this->postJson('/waitlist', ['email' => 'other@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertServerError();
            $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertServerError();

            expect(fn () => Waitlist::forget('user@example.com'))->toThrow(InvalidConfigurationException::class, 'waitlist.email_normalizer')
                ->and(fn () => Waitlist::allProjects()->forget('user@example.com'))->toThrow(InvalidConfigurationException::class, 'waitlist.email_normalizer')
                ->and(fn () => Waitlist::personalData('user@example.com'))->toThrow(InvalidConfigurationException::class, 'waitlist.email_normalizer')
                ->and(WaitlistEntry::query()->pluck('id')->all())->toBe([$tokens['entry']->id]);
            Event::assertNotDispatched(ManageLinkRequested::class);
        });

        it('still ends what a token names with a normalizer of the app left in the config, and reports the sweep over the other lists', function () {
            Exceptions::fake();
            $purposes = [...waitlistConsent(), 'newsletter' => '2026-10'];
            $beta = subscribeAndCapture('beta', 'user@example.com', $purposes);
            $gamma = subscribeAndCapture('gamma', 'user@example.com', $purposes);
            Waitlist::confirm($beta['confirm']);
            Waitlist::confirm($gamma['confirm']);

            config()->set('waitlist.email_normalizer', 'App\\Waitlist\\GmailAwareNormalizer');

            Waitlist::withdrawConsent($beta['unsubscribe'], 'newsletter');

            expect(bindingsNewsletterWithdrawn('default', 'beta'))->toBeTrue()
                ->and(bindingsNewsletterWithdrawn('default', 'gamma'))->toBeFalse();
            Exceptions::assertReported(fn (InvalidConfigurationException $exception): bool => str_contains($exception->getMessage(), 'The waitlist.email_normalizer config is no longer read'));
        });

        it('keeps every link from a mail working that ends a subscription', function () {
            $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
            Waitlist::confirm($tokens['confirm']);
            $this->withoutExceptionHandling();

            bindingsLeaveEveryKey();

            $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter", ['List-Unsubscribe' => 'One-Click'])->assertOk();
            $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

            expect(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Unsubscribed);
        });

        it('undoes a signup and a confirmation whose mail links a generator of the app would have built', function () {
            $tokens = subscribeAndCapture('beta', 'user@example.com');

            config()->set('waitlist.url_generator', 'App\\Waitlist\\SpaUrlGenerator');

            expect(fn () => Waitlist::confirm($tokens['confirm']))->toThrow(InvalidConfigurationException::class, 'waitlist.url_generator')
                ->and(fn () => Waitlist::for('beta')->add('other@example.com', waitlistConsent()))->toThrow(InvalidConfigurationException::class, 'waitlist.url_generator')
                ->and(WaitlistEntry::query()->sole()->status)->toBe(EntryStatus::Pending);
        });

        it('refuses the spam check of the endpoints, unless a closure from verifySpamUsing() stands in for the class', function () {
            config()->set('waitlist.spam_protector', 'App\\Waitlist\\TurnstileProtector');

            $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertServerError();

            expect(WaitlistEntry::query()->count())->toBe(0);

            Waitlist::verifySpamUsing(fn (Request $request): bool => true);

            $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertStatus(202);
        });
    });
});

describe('the lookup hash', function () {
    it('is keyed with the default normalizer when the app binds none', function () {
        subscribeAndCapture('beta', ' Ada+News@Example.com ');
        $entry = WaitlistEntry::query()->sole();

        expect($entry->email)->toBe('ada+news@example.com')
            ->and($entry->email_hash)->toBe(bindingsLookupHash('ada+news@example.com'))
            ->and(Waitlist::exists('ADA+NEWS@example.com'))->toBeTrue()
            ->and(Waitlist::exists('ada@example.com'))->toBeFalse();
    });

    it('is keyed with the normalizer the app binds, where the address is stored and where it is looked up', function () {
        bindingsRegisterAppProvider(fn (Application $app) => $app->bind(EmailNormalizer::class, BindingsPlusNormalizer::class));

        subscribeAndCapture('beta', ' Ada+News@Example.com ');
        Waitlist::for('beta')->add('ada+again@example.com', waitlistConsent());
        $entry = WaitlistEntry::query()->sole();

        expect($entry->email)->toBe('ada@example.com')
            ->and($entry->email_hash)->toBe(bindingsLookupHash('ada@example.com'))
            ->and(Waitlist::exists('ADA+other@example.com'))->toBeTrue()
            ->and(Waitlist::findByEmail('ada@example.com'))->toHaveCount(1);
    });
});

describe('a token', function () {
    it('reaches the address on the lists of its own project only, whichever normalizer is bound', function (?string $normalizer, array $spellings) {
        if ($normalizer !== null) {
            bindingsRegisterAppProvider(fn (Application $app) => $app->bind(EmailNormalizer::class, $normalizer));
        }

        Waitlist::define('acme', TestCase::defineTestProject(...));
        $purposes = [...waitlistConsent(), 'newsletter' => '2026-10'];
        $beta = subscribeAndCapture('beta', $spellings[0], $purposes);
        $gamma = subscribeAndCapture('gamma', $spellings[1], $purposes);
        $acme = subscribeAndCapture('beta', $spellings[2], $purposes, project: 'acme');

        foreach ([$beta, $gamma, $acme] as $tokens) {
            Waitlist::confirm($tokens['confirm']);
        }

        Waitlist::withdrawConsent($beta['unsubscribe'], 'newsletter');

        expect(bindingsNewsletterWithdrawn('default', 'beta'))->toBeTrue()
            ->and(bindingsNewsletterWithdrawn('default', 'gamma'))->toBeTrue()
            ->and(bindingsNewsletterWithdrawn('acme', 'beta'))->toBeFalse()
            ->and(Waitlist::findByUnsubscribeToken($acme['unsubscribe'])->project)->toBe('acme')
            ->and(Waitlist::project('acme')->findByEmail($spellings[0]))->toHaveCount(1);
    })->with([
        'the default normalizer' => [null, ['ada@example.com', ' ADA@example.com', 'Ada@Example.com ']],
        'a normalizer the app binds' => [BindingsPlusNormalizer::class, ['ada+beta@example.com', 'ADA+gamma@example.com', 'ada+acme@example.com']],
    ]);
});
