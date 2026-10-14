<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Closure;
use Error;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * @property-read bool $doubleOptIn whether a signup starts with a confirmation; only a signup that starts a
 *                                  cycle reads it
 */
final readonly class ListPolicy
{
    /**
     * @param  list<string>  $optional
     * @param  bool|Closure(): bool  $doubleOptIn  a closure runs each time the property is read, so a policy built
     *                                             to withdraw a purpose does not depend on a setting that only a
     *                                             signup needs
     * @param  bool  $publicCount  whether GET /count answers for the list
     * @param  bool  $publicPosition  whether the unsubscribe token reads the person's position on the list
     */
    public function __construct(
        public string $project,
        public string $list,
        public string $primary,
        public array $optional,
        private bool|Closure $doubleOptIn,
        public bool $wordingFromCallers = false,
        public bool $publicCount = false,
        public bool $publicPosition = false,
    ) {}

    // The property is private so that reading it from outside lands here.
    public function __get(string $name): bool
    {
        if ($name !== 'doubleOptIn') {
            throw new Error('Undefined property: '.self::class.'::$'.$name);
        }

        return $this->doubleOptIn instanceof Closure ? ($this->doubleOptIn)() : $this->doubleOptIn;
    }

    public function __isset(string $name): bool
    {
        return $name === 'doubleOptIn';
    }

    /**
     * @param  list<string>  $optional
     */
    public function withOptional(array $optional): self
    {
        return new self($this->project, $this->list, $this->primary, $optional, $this->doubleOptIn, $this->wordingFromCallers, $this->publicCount, $this->publicPosition);
    }

    /**
     * Whether GET /count answers for the list.
     */
    public function publishesCount(): bool
    {
        return $this->publicCount && $this->confirmsByMail();
    }

    /**
     * Whether the unsubscribe token reads the position on the list.
     */
    public function publishesPosition(): bool
    {
        return $this->publicPosition && $this->confirmsByMail();
    }

    /**
     * Without double opt-in a typed-in address counts at once, so a figure that
     * moves with it would tell whether an address was on the list already. A
     * switch that does not read publishes nothing.
     */
    private function confirmsByMail(): bool
    {
        try {
            return $this->doubleOptIn instanceof Closure ? ($this->doubleOptIn)() : $this->doubleOptIn;
        } catch (InvalidConfigurationException) {
            return false;
        }
    }

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
