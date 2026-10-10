<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use InvalidArgumentException;
use LogicException;
use Taldres\Waitlist\Enums\ConfigKey;

/**
 * Config as env() leaves it: an empty variable arrives as "", a number as a
 * numeric string and "off" as a string, which a cast or truthiness check would
 * misread. Empty or unreadable keeps the default.
 *
 * Pass a ConfigKey value, and the default comes from ConfigKey; any other key
 * needs its default passed.
 */
final class Setting
{
    public static function enabled(string $key, ?bool $default = null): bool
    {
        $default ??= self::default($key, 'bool');
        $value = config($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function integer(string $key, ?int $default = null): int
    {
        $default ??= self::default($key, 'int');

        return self::integerOrNull($key, $default) ?? $default;
    }

    /**
     * For a limit or period that null switches off.
     */
    public static function integerOrNull(string $key, ?int $default = null): ?int
    {
        $default ??= self::default($key, 'int');
        $value = config($key, $default);

        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 => (int) $value,
            default => $default,
        };
    }

    public static function string(string $key, ?string $default = null): string
    {
        $default ??= self::default($key, 'string');
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * As configured, for lists, classes and values that may be null; the
     * default only when the key is missing, so an explicit null stays null.
     */
    public static function value(string $key): mixed
    {
        return config($key, self::known($key)->default());
    }

    /**
     * @template T of 'bool'|'int'|'string'
     *
     * @param  T  $type
     * @return (T is 'bool' ? bool : (T is 'int' ? int : string))
     */
    private static function default(string $key, string $type): bool|int|string
    {
        $default = self::known($key)->default();

        return match (true) {
            $type === 'bool' && is_bool($default),
            $type === 'int' && is_int($default),
            $type === 'string' && is_string($default) => $default,
            default => throw new LogicException("[{$key}] is not read as {$type}."),
        };
    }

    private static function known(string $key): ConfigKey
    {
        return ConfigKey::tryFrom($key) ?? throw new InvalidArgumentException("[{$key}] is not a waitlist setting; pass its default.");
    }
}
