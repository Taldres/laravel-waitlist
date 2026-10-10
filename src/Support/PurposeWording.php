<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class PurposeWording implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $purpose,
        public string $version,
        public string $text,
        public bool $required,
        /** Null for a version with one text for every locale. */
        public ?string $locale = null,
    ) {}

    /**
     * Sent back by a form that renders its own copy of the text, so drift from
     * the registered wording is refused instead of recorded.
     */
    public function hash(): string
    {
        return hash('sha256', $this->text);
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
            'hash' => $this->hash(),
            'required' => $this->required,
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
