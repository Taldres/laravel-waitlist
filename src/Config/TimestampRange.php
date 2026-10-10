<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Config;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What is left of the range a timestamp column holds, which on MySQL and
 * MariaDB runs from 1970 to 2038-01-19 03:14:07 UTC. A period is compared with
 * it as a number: Carbon wraps around for one near the largest int and puts
 * the date on the wrong side of now.
 *
 * @internal
 */
final class TimestampRange
{
    /** 2038-01-19 03:14:07 UTC, the last second a MySQL or MariaDB timestamp holds. */
    public const int LAST_SECOND = 2_147_483_647;

    /**
     * Minutes a date counted forward from now may lie ahead and still be stored.
     */
    public static function minutesAhead(): int
    {
        return max(0, intdiv(self::LAST_SECOND - Carbon::now()->getTimestamp(), 60));
    }

    /**
     * Minutes that can be counted back from now before 1970, earlier than
     * any date a timestamp column holds.
     */
    public static function minutesBack(): int
    {
        return max(0, intdiv(Carbon::now()->getTimestamp(), 60));
    }

    public static function daysBack(): int
    {
        return max(0, intdiv(Carbon::now()->getTimestamp(), 86_400));
    }

    /**
     * The cutoff for rows older than $days, always in the past or now: a
     * period that wraps would otherwise move it ahead of the rows to keep.
     */
    public static function daysAgo(int $days): Carbon
    {
        if ($days < 0 || $days > self::daysBack()) {
            throw new InvalidArgumentException('A period of '.$days.' days cannot be counted back from now, it must be between 0 and '.self::daysBack().'.');
        }

        return Carbon::now()->subDays($days);
    }
}
