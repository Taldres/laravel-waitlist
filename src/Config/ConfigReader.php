<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

use Cron\CronExpression;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * Reads the waitlist config and refuses what does not fit, so WaitlistConfig
 * only ever returns checked values.
 *
 * env() leaves variables as strings, so the documented forms read as what
 * they stand for: true/false, on/off, yes/no and 1/0 as switches, whole
 * numbers written as numbers. Anything else of the wrong type is
 * refused, never cast, an empty variable included, so a blank line in .env
 * cannot turn a protection off. The package's defaults are merged in when
 * the config loads, so a key missing here means a stale config cache.
 *
 * @internal
 */
final class ConfigReader
{
    private const string LOOKBACK_LIMIT = 'a longer one would reach back before 1970, earlier than any date a timestamp column holds';

    private const string LIFETIME_LIMIT = 'a longer one would end after 2038-01-19 03:14:07 UTC, the last moment a MySQL or MariaDB timestamp column holds';

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public static function of(mixed $config): self
    {
        if (! is_array($config)) {
            // Missing, as in a config cache from before the package was installed, reads as null.
            $hint = $config === null ? ' If the config is cached, cache it again with php artisan config:cache.' : '';

            throw new InvalidConfigurationException('The waitlist config must be an array of settings, got '.get_debug_type($config).'.'.$hint);
        }

        return new self($config);
    }

    public function boolean(ConfigKey $key): bool
    {
        $value = $this->value($key);

        if (is_bool($value)) {
            return $value;
        }

        $switch = match (is_string($value) ? strtolower(trim($value)) : $value) {
            1, '1', 'true', 'on', 'yes' => true,
            0, '0', 'false', 'off', 'no' => false,
            default => null,
        };

        return $switch ?? throw self::invalid($key, 'be true or false', $value);
    }

    public function integer(ConfigKey $key, int $min): int
    {
        /** @var int */
        return $this->number($key, $min, nullable: false);
    }

    /**
     * For a limit or period that null switches off.
     */
    public function integerOrNull(ConfigKey $key, int $min): ?int
    {
        return $this->number($key, $min, nullable: true);
    }

    /**
     * For a period of days counted back from now, such as how long to keep
     * rows: nothing is older than 1970, so a longer one is a mistake.
     */
    public function lookbackDaysOrNull(ConfigKey $key, int $min): ?int
    {
        return self::atMost($key, $this->number($key, $min, nullable: true), TimestampRange::daysBack(), 'days', self::LOOKBACK_LIMIT);
    }

    /**
     * For a period of minutes counted back from now, such as a cooldown.
     */
    public function lookbackMinutesOrNull(ConfigKey $key, int $min): ?int
    {
        return self::atMost($key, $this->number($key, $min, nullable: true), TimestampRange::minutesBack(), 'minutes', self::LOOKBACK_LIMIT);
    }

    /**
     * For how long something issued now works, which null leaves unlimited.
     * The end is stored, so it may not lie past the last date a timestamp
     * column holds, which comes closer with the clock.
     */
    public function lifetimeMinutesOrNull(ConfigKey $key, int $min): ?int
    {
        return self::atMost($key, $this->number($key, $min, nullable: true), TimestampRange::minutesAhead(), 'minutes', self::LIFETIME_LIMIT);
    }

    public function lifetimeMinutes(ConfigKey $key, int $min): int
    {
        /** @var int */
        return self::atMost($key, $this->number($key, $min, nullable: false), TimestampRange::minutesAhead(), 'minutes', self::LIFETIME_LIMIT);
    }

    /**
     * Empty only where it means something, such as routes at the root.
     */
    public function string(ConfigKey $key, bool $allowEmpty = false): string
    {
        $value = $this->value($key);

        return is_string($value) && ($allowEmpty || $value !== '') ? $value : throw self::invalid($key, 'be a text', $value);
    }

    /**
     * For the path routes live under, which is empty at the root. A placeholder
     * is refused: the links in mails are built from the route name alone, with
     * no value to put in its place.
     */
    public function routePrefix(ConfigKey $key): string
    {
        $prefix = $this->string($key, allowEmpty: true);

        if (Str::contains($prefix, ['{', '}'])) {
            throw new InvalidConfigurationException("The {$key->value} config must be a path without placeholders, such as waitlist or api/waitlist, got one with braces.");
        }

        return $prefix;
    }

    /**
     * For a name that null, or an empty text, leaves unset.
     */
    public function stringOrNull(ConfigKey $key): ?string
    {
        $value = $this->value($key);

        return match (true) {
            $value === null, $value === '' => null,
            is_string($value) => $value,
            default => throw self::invalid($key, 'be a text or null', $value),
        };
    }

    /**
     * For a schedule that null, or an empty text, leaves off. Checked here,
     * as Laravel only parses it when it looks for due tasks, and a broken one
     * there stops every other task of the app as well.
     */
    public function cronOrNull(ConfigKey $key): ?string
    {
        $cron = $this->stringOrNull($key);

        if ($cron === null) {
            return null;
        }

        if (! CronExpression::isValidExpression($cron)) {
            throw new InvalidConfigurationException("The {$key->value} config must be a cron expression or null, got one that is not.");
        }

        if (! self::comesDue($cron)) {
            throw new InvalidConfigurationException("The {$key->value} config must be a cron expression that can run, or null, got one that never does.");
        }

        return $cron;
    }

    /**
     * @return list<string>
     */
    public function middleware(ConfigKey $key): array
    {
        $value = $this->value($key);
        $middleware = is_string($value) ? [$value] : $value;

        if (! is_array($middleware) || ! array_is_list($middleware) || ! self::onlyNames($middleware)) {
            throw self::invalid($key, 'be a list of middleware names', $value);
        }

        /** @var list<string> $middleware */
        return $middleware;
    }

    /**
     * @return list<string|null>
     */
    public function guards(ConfigKey $key): array
    {
        $value = $this->value($key);
        $names = is_array($value) ? Arr::whereNotNull($value) : null;

        if ($names === null || ! self::onlyNames($names)) {
            throw self::invalid($key, 'list guard names, or null for the default guard', $value);
        }

        /** @var list<string|null> */
        return array_values($value);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function columns(ConfigKey $key, array $allowed): array
    {
        $value = $this->value($key);

        if (! is_array($value) || ! array_is_list($value) || ! self::onlyNames($value)) {
            throw self::invalid($key, 'be a list of column names', $value);
        }

        /** @var list<string> $value */
        $unknown = array_diff($value, $allowed);

        if ($unknown !== []) {
            throw new InvalidConfigurationException("The {$key->value} config lists columns that may not be exported: ".implode(', ', $unknown).'.');
        }

        return $value;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    public function subclass(ConfigKey $key, string $base): string
    {
        $class = $this->value($key);

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new InvalidConfigurationException("The {$key->value} config must point to a {$base} subclass.");
        }

        if (! (new ReflectionClass($class))->isInstantiable()) {
            throw new InvalidConfigurationException("The {$key->value} config must point to a {$base} subclass that is not abstract.");
        }

        return $class;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return class-string<T>
     */
    public function implementation(ConfigKey $key, string $contract): string
    {
        $class = $this->value($key);

        if (! is_string($class) || ! is_a($class, $contract, true)) {
            throw new InvalidConfigurationException("The {$key->value} config must point to a {$contract} implementation.");
        }

        if ($class === $contract) {
            throw new InvalidConfigurationException("The {$key->value} config must name a class that implements {$contract}, not the contract itself.");
        }

        return $class;
    }

    private function number(ConfigKey $key, int $min, bool $nullable): ?int
    {
        $value = $this->value($key);

        // FILTER_VALIDATE_INT refuses what does not fit an int, which a cast
        // would quietly cap, but also leading zeros, which env() files have.
        $number = match (true) {
            $value === null && $nullable => null,
            is_int($value) => $value,
            is_string($value) => filter_var(preg_replace('/^([+-]?)0+(?=\d)/', '$1', trim($value)), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            default => false,
        };

        if (! is_int($number) && ! ($number === null && $value === null)) {
            throw self::invalid($key, $nullable ? 'be a whole number or null' : 'be a whole number', $value);
        }

        if ($number !== null && $number < $min) {
            throw new InvalidConfigurationException("The {$key->value} config must be at least {$min}.");
        }

        return $number;
    }

    /**
     * Checked against the range only after the number itself reads, so a
     * typo is reported as one.
     */
    private static function atMost(ConfigKey $key, ?int $number, int $max, string $unit, string $because): ?int
    {
        if ($number !== null && $number > $max) {
            throw new InvalidConfigurationException("The {$key->value} config must be at most {$max} {$unit}, as {$because}.");
        }

        return $number;
    }

    /**
     * A group that is no array is refused rather than read around.
     */
    private function value(ConfigKey $key): mixed
    {
        $path = Str::chopStart($key->value, 'waitlist.');
        $group = [];

        foreach (array_slice(explode('.', $path), 0, -1) as $segment) {
            $group[] = $segment;
            $value = Arr::get($this->config, implode('.', $group));

            if (Arr::has($this->config, implode('.', $group)) && ! is_array($value)) {
                throw new InvalidConfigurationException('The waitlist.'.implode('.', $group).' config must be an array of settings, got '.get_debug_type($value).'.');
            }
        }

        if (! Arr::has($this->config, $path)) {
            throw new InvalidConfigurationException("The {$key->value} config is missing. If the config is cached, cache it again with php artisan config:cache.");
        }

        return Arr::get($this->config, $path);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function onlyNames(array $values): bool
    {
        return Arr::every($values, fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    /**
     * Whether the expression has a next run at all: parsing accepts the 30th
     * of February, for which the library gives up looking after its iteration
     * limit.
     */
    private static function comesDue(string $cron): bool
    {
        try {
            (new CronExpression($cron))->getNextRunDate();
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * Names the type only: a config value may be a secret.
     */
    private static function invalid(ConfigKey $key, string $expected, mixed $value): InvalidConfigurationException
    {
        $got = $value === '' ? 'an empty text; if it comes from an empty variable in .env, remove the variable to keep the default' : get_debug_type($value);

        return new InvalidConfigurationException("The {$key->value} config must {$expected}, got {$got}.");
    }
}
