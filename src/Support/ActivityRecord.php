<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Models\WaitlistActivity;

/**
 * Includes IP and user agent although the model hides them from ordinary
 * serialization: an access request has to disclose everything stored.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ActivityRecord implements Arrayable, JsonSerializable
{
    public function __construct(
        public ActivityType $type,
        public ?string $purpose,
        public ?string $reference,
        public ?Carbon $occurredAt,
        public CarbonImmutable $occurredOn,
        public ?string $ip,
        public ?string $userAgent,
    ) {}

    public static function fromModel(WaitlistActivity $activity): self
    {
        return new self(
            type: $activity->type,
            purpose: $activity->purpose,
            reference: $activity->reference,
            occurredAt: $activity->occurred_at,
            occurredOn: CarbonImmutable::parse($activity->occurred_on),
            ip: $activity->ip,
            userAgent: $activity->user_agent,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'purpose' => $this->purpose,
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt?->format('Y-m-d H:i:s'),
            'occurred_on' => $this->occurredOn->toDateString(),
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
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
