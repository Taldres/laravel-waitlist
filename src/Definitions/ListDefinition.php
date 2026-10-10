<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Definitions;

use Closure;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * One list of a project, from ProjectDefinition::list(). The name "*" stands
 * for every list the project does not name.
 */
final class ListDefinition
{
    /**
     * @var list<string>
     */
    private array $optional = [];

    private ?bool $doubleOptIn = null;

    /**
     * @var (Closure(): array<string, mixed>)|array<string, mixed>
     */
    private Closure|array $fields = [];

    /**
     * @internal Created by ProjectDefinition::list().
     */
    public function __construct(
        public readonly string $project,
        public readonly string $name,
        public readonly string $purpose,
    ) {}

    /**
     * Purposes a person may grant on top of the primary one, each withdrawn on
     * its own. Adds to the ones named before.
     */
    public function optional(string ...$purposes): static
    {
        foreach ($purposes as $purpose) {
            ProjectDefinition::assertPurposeName($this->project, $purpose);

            if ($purpose === $this->purpose) {
                throw new InvalidConfigurationException("The list [{$this->project}/{$this->name}] lists its primary purpose [{$purpose}] as optional too.");
            }

            if (! in_array($purpose, $this->optional, true)) {
                $this->optional[] = $purpose;
            }
        }

        return $this;
    }

    /**
     * Overrides waitlist.double_opt_in.enabled for this list.
     */
    public function doubleOptIn(bool $enabled = true): static
    {
        $this->doubleOptIn = $enabled;

        return $this;
    }

    /**
     * Fields a signup on this list may send under "metadata", on top of the
     * project's: field => validation rules. A field named here replaces the
     * project's field of the same name. Pass a closure to build rule objects
     * on every validation instead of sharing one instance across requests.
     *
     * @param  (Closure(): array<string, mixed>)|array<string, mixed>  $rules
     */
    public function fields(Closure|array $rules): static
    {
        $this->fields = $rules;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getOptional(): array
    {
        return $this->optional;
    }

    /**
     * Null follows waitlist.double_opt_in.enabled.
     */
    public function getDoubleOptIn(): ?bool
    {
        return $this->doubleOptIn;
    }

    /**
     * Built anew on every call.
     *
     * @return array<string, mixed>
     */
    public function getFields(): array
    {
        return ProjectDefinition::resolveFields($this->fields, "list [{$this->project}/{$this->name}]");
    }
}
