<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Reporting;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * Counts people on the list right now; the activity log counts events and
 * deliberately cannot answer that.
 *
 * @implements Arrayable<string, mixed>
 */
final class ListSnapshot implements Arrayable, JsonSerializable
{
    use ResolvesModel;

    public function __construct(
        public readonly string $project,
        public readonly string $list,
        public readonly int $pending,
        public readonly int $confirmed,
        public readonly int $unsubscribed,
    ) {}

    public static function forList(string $list, string $project = WaitlistEntry::DEFAULT_PROJECT): self
    {
        /** @var array<string, int> $counts */
        $counts = self::modelClass()::query()
            ->onList($list, $project)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return new self(
            project: $project,
            list: $list,
            pending: (int) ($counts[EntryStatus::Pending->value] ?? 0),
            confirmed: (int) ($counts[EntryStatus::Confirmed->value] ?? 0),
            unsubscribed: (int) ($counts[EntryStatus::Unsubscribed->value] ?? 0),
        );
    }

    /**
     * Pending plus confirmed: everyone still on the list.
     */
    public function active(): int
    {
        return $this->pending + $this->confirmed;
    }

    public function total(): int
    {
        return $this->active() + $this->unsubscribed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'list' => $this->list,
            'pending' => $this->pending,
            'confirmed' => $this->confirmed,
            'unsubscribed' => $this->unsubscribed,
            'active' => $this->active(),
            'total' => $this->total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
