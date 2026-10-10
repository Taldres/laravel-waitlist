<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class SubscriptionRecord implements Arrayable, JsonSerializable
{
    /**
     * @param  list<ConsentRecord>  $consents
     */
    public function __construct(
        public int $sequence,
        public array $consents,
        public Carbon $startedAt,
        public int $confirmationCount,
        public ?Carbon $confirmedAt,
        public ?Carbon $endedAt,
        public ?EndReason $endReason,
    ) {}

    public static function fromModel(WaitlistSubscription $subscription): self
    {
        return new self(
            sequence: $subscription->sequence,
            consents: array_values($subscription->consents
                ->map(fn (WaitlistConsent $consent): ConsentRecord => ConsentRecord::fromModel($consent))
                ->all()),
            startedAt: $subscription->started_at,
            confirmationCount: $subscription->confirmation_count,
            confirmedAt: $subscription->confirmed_at,
            endedAt: $subscription->ended_at,
            endReason: $subscription->end_reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'consents' => array_map(fn (ConsentRecord $consent): array => $consent->toArray(), $this->consents),
            'started_at' => $this->startedAt->format('Y-m-d H:i:s'),
            'confirmation_count' => $this->confirmationCount,
            'confirmed_at' => $this->confirmedAt?->format('Y-m-d H:i:s'),
            'ended_at' => $this->endedAt?->format('Y-m-d H:i:s'),
            'end_reason' => $this->endReason?->value,
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
