<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\LinkGenerator;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\DefaultEmailNormalizer;
use Taldres\Waitlist\Support\DefaultLinkGenerator;
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

class BindingsUrlGenerator extends DefaultLinkGenerator {}

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
        'the URL generator' => [LinkGenerator::class, DefaultLinkGenerator::class, BindingsUrlGenerator::class],
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
