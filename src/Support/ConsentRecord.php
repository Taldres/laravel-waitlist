<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Taldres\Waitlist\Models\WaitlistConsent;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ConsentRecord implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $purpose,
        public string $version,
        public ?string $locale,
        public string $text,
        public Carbon $grantedAt,
        public ?Carbon $withdrawnAt,
    ) {}

    public static function fromModel(WaitlistConsent $consent): self
    {
        return new self(
            purpose: $consent->purpose,
            version: $consent->version,
            locale: $consent->locale,
            text: $consent->text,
            grantedAt: $consent->granted_at,
            withdrawnAt: $consent->withdrawn_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose,
            'version' => $this->version,
            'locale' => $this->locale,
            'text' => $this->text,
            'granted_at' => $this->grantedAt->format('Y-m-d H:i:s'),
            'withdrawn_at' => $this->withdrawnAt?->format('Y-m-d H:i:s'),
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
