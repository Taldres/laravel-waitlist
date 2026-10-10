<?php

declare(strict_types=1);

use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

interface BindingsAppNormalizer extends EmailNormalizer {}

final class BindingsAppUppercase implements BindingsAppNormalizer
{
    public function normalize(string $email): string
    {
        return strtoupper($email);
    }
}

abstract class BindingsAbstractNormalizer implements EmailNormalizer {}

/**
 * The message of the configuration error the container raises for $contract.
 */
function bindingsRefusal(string $contract): string
{
    // A binding that sends the container back to itself would run until memory
    // is gone, so stop the test at a depth no real resolution reaches.
    $depth = 0;

    app()->beforeResolving($contract, function () use (&$depth): void {
        if (++$depth > 10) {
            throw new LogicException('The container resolves the contract to itself again.');
        }
    });

    try {
        app($contract);
    } catch (InvalidConfigurationException $exception) {
        return $exception->getMessage();
    }

    return 'resolved';
}

describe('a config value that names the contract itself', function () {
    it('is refused, naming the key, instead of the container resolving it to itself again', function (ConfigKey $key, string $contract) {
        config()->set($key->value, $contract);

        expect(bindingsRefusal($contract))->toContain($key->value)->toContain($contract);
    })->with([
        'the email normalizer' => [ConfigKey::EmailNormalizer, EmailNormalizer::class],
        'the url generator' => [ConfigKey::UrlGenerator, ConfirmationUrlGenerator::class],
        'the catalog' => [ConfigKey::Catalog, ProjectCatalog::class],
        'the project resolver' => [ConfigKey::ProjectResolver, ProjectResolver::class],
        'the spam protector' => [ConfigKey::SpamProtector, SpamProtector::class],
    ]);
});

describe('a config value that names a class the container cannot build', function () {
    it('is refused, naming the key, when the app has not bound it', function (string $class) {
        config()->set(ConfigKey::EmailNormalizer->value, $class);

        expect(bindingsRefusal(EmailNormalizer::class))->toContain(ConfigKey::EmailNormalizer->value)->toContain($class);
    })->with([
        'an interface that extends the contract' => BindingsAppNormalizer::class,
        'an abstract class' => BindingsAbstractNormalizer::class,
    ]);

    it('is resolved through the binding the app gave it', function () {
        config()->set(ConfigKey::EmailNormalizer->value, BindingsAppNormalizer::class);
        app()->bind(BindingsAppNormalizer::class, BindingsAppUppercase::class);

        expect(app(EmailNormalizer::class)->normalize('user@example.com'))->toBe('USER@EXAMPLE.COM');
    });

    it('is resolved through a closure that builds an abstract class', function () {
        config()->set(ConfigKey::EmailNormalizer->value, BindingsAbstractNormalizer::class);
        app()->bind(BindingsAbstractNormalizer::class, fn () => new class extends BindingsAbstractNormalizer
        {
            public function normalize(string $email): string
            {
                return strrev($email);
            }
        });

        expect(app(EmailNormalizer::class)->normalize('abc'))->toBe('cba');
    });
});

describe('a class the container resolves to something else', function () {
    it('is refused, naming the key, when that is not the contract', function () {
        app()->bind(BindingsAppUppercase::class, fn () => new stdClass);
        config()->set(ConfigKey::EmailNormalizer->value, BindingsAppUppercase::class);

        expect(bindingsRefusal(EmailNormalizer::class))->toContain(ConfigKey::EmailNormalizer->value)->toContain(BindingsAppUppercase::class);
    });
});
