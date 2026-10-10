<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Reporting\Concerns\CountsActivity;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ActivityTotals implements Arrayable, JsonSerializable
{
    use CountsActivity;

    /**
     * @param  array<string, int>  $counts  keyed by ActivityType value
     * @param  array<string, array<string, int>>  $departures  departure type => status left => count
     */
    public function __construct(
        public array $counts,
        public array $departures = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Not a cohort rate: a confirmation can belong to an earlier signup, so it
     * can exceed 1. Null when nobody signed up.
     */
    public function confirmationRate(): ?float
    {
        $signups = $this->signups();

        return $signups === 0 ? null : $this->of(ActivityType::Confirmed) / $signups;
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

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
