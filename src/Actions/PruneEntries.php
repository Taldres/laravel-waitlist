<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ProjectPeriods;
use Taldres\Waitlist\Support\PruneResult;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class PruneEntries
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
        protected EraseEntry $erase,
    ) {}

    /**
     * Applies each period it can read. One that does not read is thrown once
     * the others are applied, so a typo in one setting never keeps the
     * rest of the data past its period.
     *
     * A project that promises periods of its own, see ProjectDefinition::retention(),
     * is pruned by them, and by the configured ones for each period it leaves out.
     * The configured periods then apply to every other project.
     *
     * @throws InvalidConfigurationException
     */
    public function __invoke(?string $list = null, ?string $project = null): PruneResult
    {
        $failures = [];
        $own = $this->projectsWithOwnPeriods($project);
        $except = array_keys($own);
        $onlyOwn = $project !== null && isset($own[$project]);
        $steps = [
            'expired' => [WaitlistConfig::pendingDays(...), static fn (ProjectPeriods $periods): ?int => $periods->pendingDays, fn (int $days, ?string $within, bool $leaveOutOwn): int => $this->expirePending($days, $list, $within, $leaveOutOwn ? $except : [])],
            'erased' => [WaitlistConfig::unsubscribedDays(...), static fn (ProjectPeriods $periods): ?int => $periods->unsubscribedDays, fn (int $days, ?string $within, bool $leaveOutOwn): int => $this->eraseUnsubscribed($days, $list, $within, $leaveOutOwn ? $except : [])],
            'cleared' => [WaitlistConfig::requestMetadataDays(...), static fn (ProjectPeriods $periods): ?int => $periods->requestMetadataDays, fn (int $days, ?string $within, bool $leaveOutOwn): int => $this->clearRequestMetadata($days, $list, $within, $leaveOutOwn ? $except : [])],
        ];
        $counts = ['expired' => 0, 'erased' => 0, 'cleared' => 0];

        foreach ($steps as $step => [$configured, $override, $prune]) {
            if (! $onlyOwn) {
                $counts[$step] += $this->apply($configured, fn (int $days): int => $prune($days, $project, true), $failures);
            }

            foreach ($own as $name => $periods) {
                $counts[$step] += $this->apply(fn (): ?int => $override($periods) ?? $configured(), fn (int $days): int => $prune($days, $name, false), $failures);
            }
        }

        $result = new PruneResult(expired: $counts['expired'], erased: $counts['erased'], cleared: $counts['cleared']);

        if ($failures !== []) {
            foreach (array_slice($failures, 1) as $exception) {
                app(ConfigFallback::class)->report($exception);
            }

            throw $failures[0];
        }

        return $result;
    }

    /**
     * @return array<string, ProjectPeriods> project => periods, of the projects with a retention period of their own
     */
    private function projectsWithOwnPeriods(?string $project): array
    {
        // A catalog that does not read is reported, and the configured periods
        // still apply: it must not keep the data past them.
        return ConfigFallback::read(function () use ($project): array {
            $catalog = app(ProjectCatalog::class);
            $own = [];

            foreach ($project === null ? $catalog->projects() : [$project] as $name) {
                $periods = $catalog->periods($name);

                if ($periods->overridesRetention()) {
                    $own[$name] = $periods;
                }
            }

            return $own;
        }, fallback: []);
    }

    /**
     * @param  Closure(): ?int  $period
     * @param  Closure(int): int  $prune
     * @param  list<InvalidConfigurationException>  $failures
     */
    private function apply(Closure $period, Closure $prune, array &$failures): int
    {
        try {
            $days = $period();
        } catch (InvalidConfigurationException $exception) {
            $failures[] = $exception;

            return 0;
        }

        return $days === null ? 0 : $prune($days);
    }

    /**
     * Ends abandoned signups as expired so reporting still sees them, then
     * erases them. A confirmation landing in between wins.
     *
     * @param  list<string>  $exceptProjects  projects that follow periods of their own
     */
    public function expirePending(int $days, ?string $list = null, ?string $project = null, array $exceptProjects = []): int
    {
        $erased = 0;

        static::modelClass()::query()
            ->within($list, $project)
            ->when($exceptProjects !== [], fn (Builder $query) => $query->whereNotIn('project', $exceptProjects))
            ->awaitingConfirmationSince($days)
            ->with('currentSubscription')
            ->lazyById(500)
            ->each(function (WaitlistEntry $entry) use (&$erased): void {
                $cycle = $entry->currentSubscription;

                if ($cycle === null || ! $this->lifecycle->end($cycle, EndReason::Expired, RequestContext::none())) {
                    return;
                }

                $erased += (int) ($this->erase)($entry, fn (Builder $query) => $query
                    ->where('latest_subscription_id', $cycle->getKey())
                    ->where('status', EntryStatus::Unsubscribed));
            });

        // Expired but never erased, because a listener failed or the process
        // died in between: these would otherwise wait out unsubscribed_days,
        // which is meant for people who once opted in.
        static::modelClass()::query()
            ->within($list, $project)
            ->when($exceptProjects !== [], fn (Builder $query) => $query->whereNotIn('project', $exceptProjects))
            ->where('status', EntryStatus::Unsubscribed)
            ->whereHas('latestSubscription', fn (Builder $cycle) => $cycle->where('end_reason', EndReason::Expired))
            ->lazyById(500)
            ->each(function (WaitlistEntry $entry) use (&$erased): void {
                $erased += (int) ($this->erase)($entry, fn (Builder $query) => $query
                    ->where('latest_subscription_id', $entry->latest_subscription_id)
                    ->where('status', EntryStatus::Unsubscribed));
            });

        return $erased;
    }

    /**
     * Re-checked under the row lock, so someone who comes back in the
     * meantime is kept.
     *
     * @param  list<string>  $exceptProjects  projects that follow periods of their own
     */
    public function eraseUnsubscribed(int $days, ?string $list = null, ?string $project = null, array $exceptProjects = []): int
    {
        $erased = 0;

        static::modelClass()::query()
            ->within($list, $project)
            ->when($exceptProjects !== [], fn (Builder $query) => $query->whereNotIn('project', $exceptProjects))
            ->leftBefore($days)
            ->lazyById(500)
            ->each(function (WaitlistEntry $entry) use (&$erased, $days): void {
                $erased += (int) ($this->erase)($entry, fn (Builder $query) => $query
                    ->where('latest_subscription_id', $entry->latest_subscription_id)
                    ->leftBefore($days));
            });

        return $erased;
    }

    /**
     * Writes through the query builder: log rows are immutable as models.
     *
     * @param  list<string>  $exceptProjects  projects that follow periods of their own
     */
    public function clearRequestMetadata(int $days, ?string $list = null, ?string $project = null, array $exceptProjects = []): int
    {
        return static::activityModelClass()::query()
            ->within($list, $project)
            ->when($exceptProjects !== [], fn (Builder $query) => $query->whereNotIn('project', $exceptProjects))
            ->where('occurred_at', '<', TimestampRange::daysAgo($days))
            ->where(fn (Builder $query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
            ->update(['ip' => null, 'user_agent' => null]);
    }
}
