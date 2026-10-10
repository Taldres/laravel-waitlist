<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use stdClass;
use Taldres\Waitlist\Support\ResolvesModel;
use UnexpectedValueException;

/**
 * Counts from the activity log. Erasure clears only the personal columns, so
 * the numbers survive it.
 */
class WaitlistReport
{
    use ResolvesModel;

    /**
     * @var list<string>
     */
    protected array $lists = [];

    /**
     * @var list<string>
     */
    protected array $purposes = [];

    protected ?string $project = null;

    protected ?Period $period = null;

    public function forProject(string $project): static
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Lists of the forProject() project, or of every project when none is set.
     */
    public function forList(string ...$lists): static
    {
        $this->lists = array_values(array_unique([...$this->lists, ...$lists]));

        return $this;
    }

    /**
     * Only grants and withdrawals of an optional purpose are logged with a
     * purpose; signups, confirmations and departures concern a whole cycle and
     * drop out.
     */
    public function forPurpose(string ...$purposes): static
    {
        $this->purposes = array_values(array_unique([...$this->purposes, ...$purposes]));

        return $this;
    }

    /**
     * The last $days days including today.
     */
    public function since(int $days): static
    {
        $this->period = Period::lastDays($days);

        return $this;
    }

    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to): static
    {
        $this->period = Period::of($from, $to);

        return $this;
    }

    public function daily(): DailySeries
    {
        /** @var array<string, Collection<int, stdClass>> $byDate */
        $byDate = $this->aggregate()->groupBy('occurred_on')->all();

        ksort($byDate);

        $days = [];

        foreach ($byDate as $date => $rows) {
            [$counts, $departures] = $this->tally($rows);
            $days[] = new DailyCount(CarbonImmutable::parse($date, Period::timezone())->startOfDay(), $counts, $departures);
        }

        return new DailySeries($days, $this->period ?? $this->periodFrom(array_keys($byDate)));
    }

    public function totals(): ActivityTotals
    {
        return new ActivityTotals(...$this->tally($this->aggregate()));
    }

    /**
     * Confirmed entries at the end of a day in the reporting timezone. Exact as
     * far as the log reaches back; any period or forPurpose() is ignored.
     */
    public function confirmedOn(DateTimeInterface|string $day): int
    {
        return (new ActivityTotals(...$this->tally($this->aggregate(
            until: Period::of($day, $day)->to->toDateString(),
            byPurpose: false,
        ))))->confirmedChange();
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array{0: array<string, int>, 1: array<string, array<string, int>>}
     */
    protected function tally(Collection $rows): array
    {
        $counts = [];
        $departures = [];

        foreach ($rows as $row) {
            // Depending on the driver, the count arrives as an int or a numeric string.
            if (! is_string($row->type) || ! is_numeric($row->aggregate)) {
                throw new UnexpectedValueException('The activity log returned an unreadable aggregate row.');
            }

            $type = $row->type;
            $count = (int) $row->aggregate;
            $counts[$type] = ($counts[$type] ?? 0) + $count;

            if (is_string($row->previous_status)) {
                $departures[$type][$row->previous_status] = ($departures[$type][$row->previous_status] ?? 0) + $count;
            }
        }

        return [$counts, $departures];
    }

    /**
     * Grouping on the stored date avoids engine-specific date functions, and
     * every selected column is grouped for strict SQL modes.
     *
     * @return Collection<int, stdClass>
     */
    protected function aggregate(?string $until = null, bool $byPurpose = true): Collection
    {
        $query = static::activityModelClass()::query()
            ->toBase()
            ->selectRaw('occurred_on, type, previous_status, count(*) as aggregate')
            ->groupBy('occurred_on', 'type', 'previous_status');

        if ($this->project !== null) {
            $query->where('project', $this->project);
        }

        if ($this->lists !== []) {
            $query->whereIn('list', $this->lists);
        }

        if ($byPurpose && $this->purposes !== []) {
            $query->whereIn('purpose', $this->purposes);
        }

        if ($until !== null) {
            $query->where('occurred_on', '<=', $until);
        } elseif ($this->period !== null) {
            $query->whereBetween('occurred_on', [
                $this->period->from->toDateString(),
                $this->period->to->toDateString(),
            ]);
        }

        return $query->get();
    }

    /**
     * @param  list<string>  $dates
     */
    protected function periodFrom(array $dates): Period
    {
        if ($dates === []) {
            return Period::lastDays(1);
        }

        return Period::of(min($dates), max($dates));
    }
}
