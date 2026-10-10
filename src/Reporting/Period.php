<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Support\Setting;

/**
 * A closed range of days in waitlist.reporting.timezone, the zone activity rows
 * are dated in.
 */
final readonly class Period
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function of(DateTimeInterface|string $from, DateTimeInterface|string $to): self
    {
        return new self(self::day($from), self::day($to));
    }

    /**
     * The last $days days including today.
     */
    public static function lastDays(int $days): self
    {
        $today = self::day(CarbonImmutable::now());

        return new self($today->subDays(max($days, 1) - 1), $today);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function dates(): array
    {
        $dates = [];

        for ($date = $this->from; $date->lessThanOrEqualTo($this->to); $date = $date->addDay()) {
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * An empty WAITLIST_REPORTING_TIMEZONE arrives as "", which means unset.
     */
    public static function timezone(): string
    {
        $timezone = Setting::value(ConfigKey::ReportingTimezone->value) ?: config('app.timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * A moment is converted first: parse() would keep its own zone and land on
     * another day than its activity rows are dated in.
     */
    private static function day(DateTimeInterface|string $value): CarbonImmutable
    {
        return ($value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone(self::timezone())
            : CarbonImmutable::parse($value, self::timezone()))->startOfDay();
    }
}
