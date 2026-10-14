<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Taldres\Waitlist\Models\WaitlistEntry;

/**
 * For models with project and list columns.
 */
trait ScopesToProject
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnList(Builder $query, string $list, string $project = WaitlistEntry::DEFAULT_PROJECT): Builder
    {
        return $query->where('project', $project)->where('list', $list);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInProject(Builder $query, string $project): Builder
    {
        return $query->where('project', $project);
    }

    /**
     * A list without a project is the default project's list.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithin(Builder $query, ?string $list = null, ?string $project = null): Builder
    {
        return match (true) {
            $list !== null => $query->onList($list, $project ?? WaitlistEntry::DEFAULT_PROJECT),
            $project !== null => $query->inProject($project),
            default => $query,
        };
    }

    /**
     * Rows of every project but the given ones.
     *
     * @param  Builder<static>  $query
     * @param  list<string>  $projects
     * @return Builder<static>
     */
    public function scopeOutsideProjects(Builder $query, array $projects): Builder
    {
        return $projects === [] ? $query : $query->whereNotIn('project', $projects);
    }
}
