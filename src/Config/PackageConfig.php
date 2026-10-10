<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

/**
 * config/waitlist.php as the package ships it, the one place every default
 * lives, merged into the app's config when the package registers.
 *
 * @internal
 */
final class PackageConfig
{
    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        /** @var array<string, mixed> */
        return require dirname(__DIR__, 2).'/config/waitlist.php';
    }

    /**
     * Fills in what an app's config leaves out, following the package's
     * structure: a group the package has is merged key by key, while a list
     * or a value the app sets replaces the package's as a whole. Whatever the
     * app sets stays, a wrong type included, so ConfigReader can refuse it.
     */
    public static function merge(mixed $app): mixed
    {
        return is_array($app) ? self::fill(self::defaults(), $app) : $app;
    }

    /**
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $app
     * @return array<array-key, mixed>
     */
    private static function fill(array $package, array $app): array
    {
        foreach ($package as $key => $default) {
            if (! array_key_exists($key, $app)) {
                $app[$key] = $default;
            } elseif (self::isGroup($default) && is_array($app[$key])) {
                $app[$key] = self::fill($default, $app[$key]);
            }
        }

        return $app;
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isGroup(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }
}
