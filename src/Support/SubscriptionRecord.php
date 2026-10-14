<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
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
        public DateTimeInterface $startedAt,
        public int $confirmationCount,
        public ?DateTimeInterface $confirmedAt,
        public ?DateTimeInterface $endedAt,
        public ?EndReason $endReason,
        public ?DateTimeInterface $reconfirmationRequestedAt = null,
        public ?DateTimeInterface $reconfirmedAt = null,
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
            // Missing until their migration ran.
            reconfirmationRequestedAt: array_key_exists('reconfirmation_requested_at', $subscription->getAttributes()) ? $subscription->reconfirmation_requested_at : null,
            reconfirmedAt: array_key_exists('reconfirmed_at', $subscription->getAttributes()) ? $subscription->reconfirmed_at : null,
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
            'reconfirmation_requested_at' => $this->reconfirmationRequestedAt?->format('Y-m-d H:i:s'),
            'reconfirmed_at' => $this->reconfirmedAt?->format('Y-m-d H:i:s'),
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
