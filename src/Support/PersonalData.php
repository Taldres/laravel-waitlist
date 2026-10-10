<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * A data copy of one entry for access requests. Tokens and token hashes are
 * omitted as credentials; this is not by itself a complete response under
 * GDPR Art. 15.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PersonalData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<SubscriptionRecord>  $subscriptions
     * @param  list<ActivityRecord>  $activity
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $project,
        public string $list,
        public string $email,
        public EntryStatus $status,
        public array $subscriptions,
        public array $activity,
        public array $metadata,
        public Carbon $createdAt,
        public Carbon $updatedAt,
    ) {}

    public static function fromEntry(WaitlistEntry $entry): self
    {
        $entry->loadMissing(['subscriptions.consents', 'activity']);

        return new self(
            project: $entry->project,
            list: $entry->list,
            email: $entry->email,
            status: $entry->status,
            subscriptions: array_values($entry->subscriptions
                ->map(fn (WaitlistSubscription $subscription): SubscriptionRecord => SubscriptionRecord::fromModel($subscription))
                ->all()),
            activity: array_values($entry->activity
                ->map(fn (WaitlistActivity $activity): ActivityRecord => ActivityRecord::fromModel($activity))
                ->all()),
            metadata: $entry->metadata ?? [],
            createdAt: $entry->created_at ?? Carbon::now(),
            updatedAt: $entry->updated_at ?? Carbon::now(),
        );
    }

    /**
     * @return list<string>
     */
    public function effectivePurposes(): array
    {
        $latest = $this->subscriptions[array_key_last($this->subscriptions) ?? -1] ?? null;

        if ($latest === null || $latest->endedAt !== null || $latest->confirmedAt === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (ConsentRecord $consent): string => $consent->purpose,
            array_filter($latest->consents, fn (ConsentRecord $consent): bool => $consent->withdrawnAt === null),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'list' => $this->list,
            'email' => $this->email,
            'status' => $this->status->value,
            'subscriptions' => array_map(fn (SubscriptionRecord $record): array => $record->toArray(), $this->subscriptions),
            'activity' => array_map(fn (ActivityRecord $record): array => $record->toArray(), $this->activity),
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
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
