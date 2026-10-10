<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Closure;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ListDefinition;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistWording;

/**
 * Reads the projects registered with Waitlist::define(). The default project
 * always exists; until it is defined, it has no lists, so a call that forgets
 * project() fails instead of signing someone up for the wrong product.
 */
class DefinedProjectCatalog implements ProjectCatalog
{
    /**
     * @var array<string, array<string, array<string, string|array<string, string>>>> project => purpose => versions
     */
    protected array $loaded = [];

    public function __construct(
        protected ProjectDefinitions $definitions,
    ) {}

    public function policy(string $project, string $list): ?ListPolicy
    {
        $definition = $this->list($project, $list);

        if ($definition === null) {
            return null;
        }

        return new ListPolicy(
            project: $project,
            list: $list,
            primary: $definition->purpose,
            optional: $definition->getOptional(),
            doubleOptIn: $definition->getDoubleOptIn() ?? $this->defaultDoubleOptIn(),
            wordingFromCallers: $this->definitions->get($project)?->getWordingFromCallers() ?? false,
        );
    }

    public function versions(string $project, string $purpose): array
    {
        $definition = $this->definitions->get($project);
        $versions = $definition?->getVersions($purpose) ?? [];

        if ($definition?->getWordingFromCallers() !== true) {
            return $versions;
        }

        foreach ($this->stored($project)[$purpose] ?? [] as $version => $wording) {
            $versions[$version] ??= $wording;
        }

        return $versions;
    }

    public function fields(string $project, string $list): array
    {
        $definition = $this->list($project, $list);

        if ($definition === null) {
            return [];
        }

        return array_replace($this->definitions->get($project)?->getFields() ?? [], $definition->getFields());
    }

    public function urlPattern(string $project, string $action): ?string
    {
        return $this->definitions->get($project)?->getUrl($action);
    }

    public function projects(): array
    {
        return array_values(array_unique([WaitlistEntry::DEFAULT_PROJECT, ...$this->definitions->names()]));
    }

    public function lists(string $project): array
    {
        return array_map('strval', array_keys($this->definitions->get($project)?->getLists() ?? []));
    }

    /**
     * Loaded once per project: one signup asks for several purposes, several
     * times each.
     *
     * @return array<string, array<string, string|array<string, string>>>
     */
    protected function stored(string $project): array
    {
        return $this->loaded[$project] ??= $this->load($project);
    }

    /**
     * @return array<string, array<string, string|array<string, string>>>
     */
    protected function load(string $project): array
    {
        $purposes = [];

        $rows = WaitlistWording::query()
            ->where('project', $project)
            ->whereNull('retired_at')
            ->orderBy('id')
            ->get(['purpose', 'version', 'locale', 'text']);

        foreach ($rows as $row) {
            if ($row->locale === '') {
                $purposes[$row->purpose][$row->version] = $row->text;
            } else {
                $texts = $purposes[$row->purpose][$row->version] ?? [];
                $purposes[$row->purpose][$row->version] = [...(is_array($texts) ? $texts : []), $row->locale => $row->text];
            }
        }

        return $purposes;
    }

    protected function list(string $project, string $list): ?ListDefinition
    {
        $definition = $this->definitions->get($project);

        return $definition?->getList($list) ?? $definition?->getList('*');
    }

    /**
     * A switch that does not read is not refused here, because a policy also
     * serves leaving and withdrawing, which need none. Starting a cycle reads
     * it from the policy and gets the error.
     *
     * @return bool|Closure(): bool
     */
    protected function defaultDoubleOptIn(): bool|Closure
    {
        try {
            return WaitlistConfig::doubleOptIn();
        } catch (InvalidConfigurationException) {
            return WaitlistConfig::doubleOptIn(...);
        }
    }
}
