<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Closure;
use Error;

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
     */
    public function __construct(
        public string $project,
        public string $list,
        public string $primary,
        public array $optional,
        private bool|Closure $doubleOptIn,
        public bool $wordingFromCallers = false,
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
        return new self($this->project, $this->list, $this->primary, $optional, $this->doubleOptIn, $this->wordingFromCallers);
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
