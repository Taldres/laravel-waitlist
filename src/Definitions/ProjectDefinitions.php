<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Definitions;

use Closure;

/**
 * The projects registered with Waitlist::define(). A callback runs when its
 * project is first needed, not at boot, so a broken definition surfaces where
 * the waitlist is used instead of breaking every artisan command; the result is
 * kept until the project is defined again.
 */
final class ProjectDefinitions
{
    /**
     * @var array<string, Closure(ProjectDefinition): mixed>
     */
    private array $callbacks = [];

    /**
     * @var array<string, ProjectDefinition>
     */
    private array $built = [];

    /**
     * Replaces an earlier definition of the same project.
     *
     * @param  Closure(ProjectDefinition): mixed  $callback
     */
    public function define(string $project, Closure $callback): void
    {
        // Validates the name now rather than on first use.
        new ProjectDefinition($project);

        $this->callbacks[$project] = $callback;

        unset($this->built[$project]);
    }

    public function get(string $project): ?ProjectDefinition
    {
        if (isset($this->built[$project])) {
            return $this->built[$project];
        }

        if (! isset($this->callbacks[$project])) {
            return null;
        }

        $definition = new ProjectDefinition($project);

        ($this->callbacks[$project])($definition);

        return $this->built[$project] = $definition;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->callbacks));
    }
}
