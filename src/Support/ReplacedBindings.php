<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Contracts\SpamProtector;

/**
 * The contracts that a config key swapped before a container binding replaced
 * it, with the class the package binds for each.
 *
 * @internal
 */
final class ReplacedBindings
{
    /**
     * By the key of the earlier config: the contract to bind instead, and the
     * default class, which is also what waitlist:install published under the key.
     *
     * @var array<string, array{0: class-string, 1: class-string}>
     */
    public const array KEYS = [
        'waitlist.catalog' => [ProjectCatalog::class, DefinedProjectCatalog::class],
        'waitlist.url_generator' => [ConfirmationUrlGenerator::class, DefaultConfirmationUrlGenerator::class],
        'waitlist.project_resolver' => [ProjectResolver::class, DefaultProjectResolver::class],
        'waitlist.email_normalizer' => [EmailNormalizer::class, DefaultEmailNormalizer::class],
        'waitlist.spam_protector' => [SpamProtector::class, NullSpamProtector::class],
    ];

    /**
     * Why a key of an earlier config must not be ignored, or null while it is
     * unset or names the default the package binds anyway. Ignoring a class of
     * the app's own would change the project of a signup or the lookup hash
     * without a word, so the package refuses it instead. A key set to null
     * counts as set: no earlier config accepted that for a class.
     */
    public static function refusal(string $key): ?string
    {
        if (! isset(self::KEYS[$key])) {
            return null;
        }

        [$contract, $default] = self::KEYS[$key];

        return ! config()->has($key) || config($key) === $default
            ? null
            : "The {$key} config is no longer read: bind {$contract} in a service provider instead, see docs/extending.md, and remove the key from config/waitlist.php (cache the config again if it is cached).";
    }
}
