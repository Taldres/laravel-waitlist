<?php

declare(strict_types=1);

use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\RequestContext;

// The switch for confirmation and the one demanding the hash of the wording
// shown matter when a signup or a new consent is recorded. Leaving and
// withdrawing record nothing, so a setting that does not read must not stand in
// the way of either.

dataset('unreadable settings', [
    'double_opt_in.enabled is not a switch' => [ConfigKey::DoubleOptIn, 'maybe'],
    'double_opt_in.enabled is a list' => [ConfigKey::DoubleOptIn, ['yes']],
    'wording.require_hash is not a switch' => [ConfigKey::WordingRequireHash, 'maybe'],
    'wording.require_hash is a list' => [ConfigKey::WordingRequireHash, ['yes']],
]);

function confirmedSubscriber(array $purposes): WaitlistEntry
{
    $tokens = subscribeAndCapture('beta', 'user@example.com', $purposes);
    Waitlist::confirm($tokens['confirm']);

    return $tokens['entry'];
}

function newsletterGranted(): bool
{
    return WaitlistConsent::query()->where('purpose', 'newsletter')->whereNull('withdrawn_at')->exists();
}

describe('the manage page', function () {
    beforeEach(function () {
        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);

        require __DIR__.'/../../routes/waitlist.php';
    });

    it('withdraws a purpose whatever the settings of a signup are', function (ConfigKey $key, mixed $value) {
        $this->withoutExceptionHandling();

        $manage = manageTokenFor(confirmedSubscriber([...waitlistConsent(), 'newsletter' => '2026-10']));

        config()->set($key->value, $value);

        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => waitlistConsent()])->assertOk();

        expect(newsletterGranted())->toBeFalse()
            ->and(WaitlistConsent::query()->where('purpose', 'newsletter')->whereNotNull('withdrawn_at')->count())->toBe(1);
    })->with('unreadable settings');

    it('keeps the purposes in force without a hash', function (mixed $value) {
        $this->withoutExceptionHandling();

        $manage = manageTokenFor(confirmedSubscriber([...waitlistConsent(), 'newsletter' => '2026-10']));
        $before = WaitlistActivity::query()->count();

        config()->set(ConfigKey::WordingRequireHash->value, $value);

        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => [...waitlistConsent(), 'newsletter' => '2026-10']])->assertOk();

        expect(newsletterGranted())->toBeTrue()
            ->and(WaitlistConsent::query()->count())->toBe(2)
            ->and(WaitlistActivity::query()->count())->toBe($before);
    })->with([
        'demanded' => true,
        'unreadable' => 'maybe',
    ]);

    it('withdraws a purpose without a hash when hashes are demanded', function () {
        $manage = manageTokenFor(confirmedSubscriber([...waitlistConsent(), 'newsletter' => '2026-10']));

        config()->set(ConfigKey::WordingRequireHash->value, true);

        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => waitlistConsent()])->assertOk();

        expect(newsletterGranted())->toBeFalse();
    });

    it('demands the hash of a purpose it records', function () {
        $manage = manageTokenFor(confirmedSubscriber(waitlistConsent()));

        config()->set(ConfigKey::WordingRequireHash->value, true);

        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => [...waitlistConsent(), 'newsletter' => '2026-10']])
            ->assertStatus(422)
            ->assertJsonPath('errors.purposes.0', 'The choice for [newsletter] needs the hash of the wording shown.');

        expect(newsletterGranted())->toBeFalse();

        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => [
            ...waitlistConsent(),
            'newsletter' => ['version' => '2026-10', 'hash' => hash('sha256', 'Also send me the newsletter.')],
        ]])->assertOk();

        expect(newsletterGranted())->toBeTrue();
    });

    it('refuses to record a purpose while the hash setting does not read', function () {
        $this->withoutExceptionHandling();

        $manage = manageTokenFor(confirmedSubscriber(waitlistConsent()));

        config()->set(ConfigKey::WordingRequireHash->value, 'maybe');

        expect(fn () => $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => [...waitlistConsent(), 'newsletter' => '2026-10']]))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.wording.require_hash config must be true or false, got string.');

        expect(newsletterGranted())->toBeFalse();
    });

    it('records a purpose on a running cycle without asking for confirmation', function () {
        $this->withoutExceptionHandling();

        $manage = manageTokenFor(confirmedSubscriber(waitlistConsent()));

        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        $this->putJson("/waitlist/manage/{$manage}/purposes", ['purposes' => [...waitlistConsent(), 'newsletter' => '2026-10']])->assertOk();

        expect(newsletterGranted())->toBeTrue();
    });

    it('serves the wording of a list whatever the double opt-in default is', function () {
        $this->withoutExceptionHandling();

        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        $this->getJson('/waitlist/purposes?list=beta')->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('granting a purpose', function () {
    it('needs neither setting', function (ConfigKey $key, mixed $value) {
        $manage = manageTokenFor(confirmedSubscriber(waitlistConsent()));

        config()->set($key->value, $value);

        Waitlist::grantConsent($manage, 'newsletter', '2026-10');

        expect(newsletterGranted())->toBeTrue();
    })->with('unreadable settings');
});

describe('a signup', function () {
    it('is refused while the double opt-in default does not read', function () {
        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.double_opt_in.enabled config must be true or false, got string.');

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('is refused alike for a known address, and before any wording a caller sent is registered', function () {
        confirmedSubscriber(waitlistConsent());
        Waitlist::define('acme', function (ProjectDefinition $project): void {
            $project->wordingFromCallers();
            $project->list('beta', purpose: 'launch');
        });

        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))->toThrow(InvalidConfigurationException::class)
            ->and(fn () => Waitlist::project('acme')->for('beta')->add('user@example.com', ['launch' => ['version' => '2026-10', 'text' => 'Tell me when it launches.']], context: new RequestContext(caller: 'server#1')))->toThrow(InvalidConfigurationException::class)
            ->and(WaitlistWording::query()->count())->toBe(0);
    });

    it('does not read the default for a list that decides on its own', function (bool $own) {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->doubleOptIn($own));

        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        $result = Waitlist::for('beta')->add('user@example.com', waitlistConsent());

        expect($result->subscription->isConfirmed())->toBe(! $own);
    })->with([
        'with confirmation' => true,
        'without confirmation' => false,
    ]);

    it('is refused while the hash setting does not read', function () {
        config()->set(ConfigKey::WordingRequireHash->value, 'maybe');

        expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.wording.require_hash config must be true or false, got string.');

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('records the wording of a choice that comes with its hash', function () {
        config()->set(ConfigKey::WordingRequireHash->value, true);

        Waitlist::for('beta')->add('user@example.com', ['waitlist' => ['version' => '2026-10', 'hash' => hash('sha256', 'Email me when early access opens.')]]);

        expect(WaitlistConsent::query()->count())->toBe(1);
    });
});

describe('a list policy', function () {
    it('takes a deferred double opt-in decision only when it is read', function () {
        $reads = 0;
        $policy = new ListPolicy('default', 'beta', 'waitlist', ['newsletter'], function () use (&$reads): bool {
            $reads++;

            return true;
        });

        expect($policy->allows('newsletter'))->toBeTrue()
            ->and($policy->purposes())->toBe(['waitlist', 'newsletter'])
            ->and($policy->withOptional([])->optional)->toBe([])
            ->and($reads)->toBe(0)
            ->and($policy->doubleOptIn)->toBeTrue()
            ->and($reads)->toBe(1)
            ->and($policy->withOptional([])->doubleOptIn)->toBeTrue();
    });

    it('reports a deferred decision that does not read where it is asked for', function () {
        $policy = new ListPolicy('default', 'beta', 'waitlist', [], function (): bool {
            throw new InvalidConfigurationException('Unreadable.');
        });

        expect(fn () => $policy->doubleOptIn)->toThrow(InvalidConfigurationException::class, 'Unreadable.')
            ->and(fn () => $policy->doubleOptIn)->toThrow(InvalidConfigurationException::class, 'Unreadable.')
            ->and(fn () => $policy->withOptional([])->doubleOptIn)->toThrow(InvalidConfigurationException::class, 'Unreadable.');
    });

    it('still takes the decision as a plain bool', function (bool $decision) {
        $policy = new ListPolicy('default', 'beta', 'waitlist', [], $decision);

        expect($policy->doubleOptIn)->toBe($decision)
            ->and(isset($policy->doubleOptIn))->toBeTrue()
            ->and($policy->withOptional(['newsletter']))->toEqual(new ListPolicy('default', 'beta', 'waitlist', ['newsletter'], $decision));
    })->with([true, false]);

    it('cannot be changed from outside', function (bool|Closure $decision) {
        $policy = new ListPolicy('default', 'beta', 'waitlist', [], $decision);

        expect(fn () => $policy->doubleOptIn = ! $decision)->toThrow(Error::class);
    })->with([
        'a bool' => true,
        'a deferred decision' => fn () => fn (): bool => true,
    ]);

    it('knows no other property', function () {
        $policy = new ListPolicy('default', 'beta', 'waitlist', [], fn (): bool => true);

        expect(fn () => $policy->unknown)->toThrow(Error::class, 'Undefined property');
    });

    it('is built from the catalog although the default does not read', function () {
        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        $policy = app(ProjectCatalog::class)->policy('default', 'beta');

        expect($policy)->toBeInstanceOf(ListPolicy::class)
            ->and(fn () => $policy->doubleOptIn)->toThrow(InvalidConfigurationException::class, 'The waitlist.double_opt_in.enabled config must be true or false, got string.');
    });

    it('takes the default as it is when it reads', function (bool $default) {
        config()->set(ConfigKey::DoubleOptIn->value, $default);

        expect(app(ProjectCatalog::class)->policy('default', 'beta'))->toEqual(new ListPolicy('default', 'beta', 'waitlist', ['newsletter'], $default));
    })->with([true, false]);

    it('keeps its decision deferred when the registry drops a purpose without wording', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('extra', [])->list('beta', purpose: 'waitlist')->optional('newsletter', 'extra'), lists: false);

        config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

        $policy = app(PurposeRegistry::class)->policy('default', 'beta');

        expect($policy->optional)->toBe(['newsletter'])
            ->and(fn () => $policy->doubleOptIn)->toThrow(InvalidConfigurationException::class, 'double_opt_in.enabled');
    });
});
