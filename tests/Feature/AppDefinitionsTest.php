<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\WaitlistServiceProvider;

/*
 * What the app defines before the package boots stays its own, whatever the
 * order of the providers.
 */

describe('a limiter the app defined first', function () {
    beforeEach(function () {
        // The skeleton's .env may name the database cache; a limiter needs a store.
        config()->set('cache.default', 'array');
        app()->forgetInstance(CacheRateLimiter::class);
        Facade::clearResolvedInstance(CacheRateLimiter::class);
    });

    it('keeps its place, and the other package limiter is still registered', function (string $name, string $other) {
        RateLimiter::for($name, fn () => Limit::perMinute(1)->by('mine'));

        (new WaitlistServiceProvider(app()))->boot();

        $limit = RateLimiter::limiter($name)(Request::create('/'));

        expect($limit)->toBeInstanceOf(Limit::class)
            ->and($limit->maxAttempts)->toBe(1)
            ->and($limit->key)->toBe('mine')
            ->and(RateLimiter::limiter($other))->not->toBeNull();
    })->with([
        'signups' => [WaitlistServiceProvider::SIGNUP_LIMITER, WaitlistServiceProvider::LINKS_LIMITER],
        'links' => [WaitlistServiceProvider::LINKS_LIMITER, WaitlistServiceProvider::SIGNUP_LIMITER],
    ]);

    it('is replaced by nothing when the package registers both', function () {
        (new WaitlistServiceProvider(app()))->boot();

        expect(RateLimiter::limiter(WaitlistServiceProvider::SIGNUP_LIMITER))->not->toBeNull()
            ->and(RateLimiter::limiter(WaitlistServiceProvider::LINKS_LIMITER))->not->toBeNull();
    });
});

it('keeps a binding of a contract that the app made first', function (string $contract) {
    $mine = app($contract);
    app()->instance($contract, $mine);

    (new WaitlistServiceProvider(app()))->register();

    expect(app($contract))->toBe($mine);
})->with([
    'the spam protector' => SpamProtector::class,
    'the email normalizer' => EmailNormalizer::class,
    'the url generator' => ConfirmationUrlGenerator::class,
    'the catalog' => ProjectCatalog::class,
    'the project resolver' => ProjectResolver::class,
]);
