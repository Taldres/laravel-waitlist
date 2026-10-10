<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Reporting\Concerns\CountsActivity;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class DailyCount implements Arrayable, JsonSerializable
{
    use CountsActivity;

    /**
     * @param  array<string, int>  $counts  keyed by ActivityType value
     * @param  array<string, array<string, int>>  $departures  departure type => status left => count
     */
    public function __construct(
        public CarbonImmutable $date,
        public array $counts,
        public array $departures = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $counts = [];

        foreach (ActivityType::cases() as $type) {
            $counts[$type->value] = $this->of($type);
        }

        return ['date' => $this->date->toDateString()] + $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
