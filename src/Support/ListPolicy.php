<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

final readonly class ListPolicy
{
    /**
     * @param  list<string>  $optional
     */
    public function __construct(
        public string $project,
        public string $list,
        public string $primary,
        public array $optional,
        public bool $doubleOptIn,
        public bool $wordingFromCallers = false,
    ) {}

    public function allows(string $purpose): bool
    {
        return $purpose === $this->primary || in_array($purpose, $this->optional, true);
    }

    /**
     * @return list<string>
     */
    public function purposes(): array
    {
        return [$this->primary, ...$this->optional];
    }
}
