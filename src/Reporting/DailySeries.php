<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting;

use ArrayIterator;
use Countable;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Taldres\Waitlist\Enums\ActivityType;
use Traversable;

/**
 * Oldest first. Days without activity are absent until fillGaps() adds them.
 *
 * @implements Arrayable<int, array<string, mixed>>
 * @implements IteratorAggregate<int, DailyCount>
 */
final readonly class DailySeries implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<DailyCount>  $days
     */
    public function __construct(
        public array $days,
        public Period $period,
    ) {}

    public function fillGaps(): self
    {
        $existing = [];

        foreach ($this->days as $day) {
            $existing[$day->date->toDateString()] = $day;
        }

        $filled = [];

        foreach ($this->period->dates() as $date) {
            $filled[] = $existing[$date->toDateString()] ?? new DailyCount($date, []);
        }

        return new self($filled, $this->period);
    }

    public function forDate(DateTimeInterface|string $date): ?DailyCount
    {
        $key = Period::of($date, $date)->from->toDateString();

        foreach ($this->days as $day) {
            if ($day->date->toDateString() === $key) {
                return $day;
            }
        }

        return null;
    }

    public function sum(ActivityType $type): int
    {
        return array_sum(array_map(fn (DailyCount $day): int => $day->of($type), $this->days));
    }

    public function totals(): ActivityTotals
    {
        $counts = [];
        $departures = [];

        foreach (ActivityType::cases() as $type) {
            $counts[$type->value] = $this->sum($type);
        }

        foreach ($this->days as $day) {
            foreach ($day->departures as $type => $statuses) {
                foreach ($statuses as $status => $count) {
                    $departures[$type][$status] = ($departures[$type][$status] ?? 0) + $count;
                }
            }
        }

        return new ActivityTotals($counts, $departures);
    }

    public function count(): int
    {
        return count($this->days);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->days);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (DailyCount $day): array => $day->toArray(), $this->days);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
