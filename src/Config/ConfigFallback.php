<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

use Closure;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * For a step that must not wait for a setting it can do without, such as
 * dating an activity row: the setting's error is reported and the step goes
 * on with the fallback. Only configuration errors are caught.
 *
 * Bound as scoped, so an error is reported once per request, job or command,
 * rather than once for every entry of a bulk run.
 *
 * @internal
 */
final class ConfigFallback
{
    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @template T
     * @template F
     *
     * @param  Closure(): T  $read
     * @param  F  $fallback
     * @return T|F
     */
    public static function read(Closure $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (InvalidConfigurationException $exception) {
            app(self::class)->report($exception);

            return $fallback;
        }
    }

    public function report(InvalidConfigurationException $exception): void
    {
        if (isset($this->reported[$exception->getMessage()])) {
            return;
        }

        $this->reported[$exception->getMessage()] = true;

        report($exception);
    }
}
