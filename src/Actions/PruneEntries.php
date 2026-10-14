<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\RetentionStep;
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
        $counts = ['expired' => 0, 'erased' => 0, 'cleared' => 0, 'asked' => 0, 'lapsed' => 0];
        // Asking runs last, so a listener that throws or a schema without the
        // columns it needs never holds up the erasures.
        $reconfirmable = $this->hasReconfirmationColumns();

        foreach (RetentionStep::cases() as $step) {
            if ($step === RetentionStep::Asked && ! $reconfirmable) {
                $this->apply(
                    fn (): ?int => array_filter($own, static fn (ProjectPeriods $periods): bool => $periods->reconfirmAfterDays !== null) !== [] ? 1 : $step->configuredDays(),
                    static fn (): never => throw new InvalidConfigurationException('Confirmed addresses are to be asked to confirm again (retention.reconfirm_after_days), but the waitlist_subscriptions table lacks its columns: publish the migrations with php artisan vendor:publish --tag=waitlist-migrations and run php artisan migrate.'),
                    $failures,
                );

                continue;
            }

            if (! $onlyOwn) {
                $counts[$step->value] += $this->apply(fn (): ?int => $step->configuredDays(), fn (int $days): int => $this->prune($step, $days, $list, $project, $except), $failures);
            }

            foreach ($own as $name => $periods) {
                $counts[$step->value] += $this->apply(fn (): ?int => $step->ownDays($periods) ?? $step->configuredDays(), fn (int $days): int => $this->prune($step, $days, $list, $name, [], $periods), $failures);
            }
        }

        if ($reconfirmable) {
            $counts['lapsed'] = $this->lapse($list, $project);
        }

        $result = new PruneResult(
            expired: $counts['expired'],
            erased: $counts['erased'],
            cleared: $counts['cleared'],
            asked: $counts['asked'],
            lapsed: $counts['lapsed'],
        );

        if ($failures !== []) {
            foreach (array_slice($failures, 1) as $exception) {
                app(ConfigFallback::class)->report($exception);
            }

            throw $failures[0];
        }

        return $result;
    }

    /**
     * An application that updated the package without running its newer
     * migrations still has its retention applied.
     */
    private function hasReconfirmationColumns(): bool
    {
        $model = new (static::subscriptionModelClass());

        return $model->getConnection()->getSchemaBuilder()->hasColumns($model->getTable(), ['reconfirmation_requested_at', 'reconfirmed_at']);
    }

    /**
     * @param  list<string>  $except  projects that follow periods of their own
     *
     * @throws InvalidConfigurationException
     */
    private function prune(RetentionStep $step, int $days, ?string $list, ?string $project, array $except, ?ProjectPeriods $periods = null): int
    {
        return match ($step) {
            RetentionStep::Expired => $this->expirePending($days, $list, $project, $except),
            RetentionStep::Erased => $this->eraseUnsubscribed($days, $list, $project, $except),
            RetentionStep::Cleared => $this->clearRequestMetadata($days, $list, $project, $except),
            RetentionStep::Asked => $this->askAgain($days, $periods->reconfirmWithinDays ?? WaitlistConfig::reconfirmWithinDays(), $list, $project, $except),
        };
    }

    /**
     * Erasing cannot be undone, so a catalog that does not read stops the run
     * before anything is deleted: the configured periods are not a stand-in for
     * the ones a project promised, which may be longer.
     *
     * @return array<string, ProjectPeriods> project => periods, of the projects with a retention period of their own
     *
     * @throws InvalidConfigurationException
     */
    private function projectsWithOwnPeriods(?string $project): array
    {
        $catalog = app(ProjectCatalog::class);
        $own = [];

        foreach ($project === null ? $catalog->projects() : [$project] as $name) {
            $periods = $catalog->periods($name);

            if ($periods->overridesRetention()) {
                $own[$name] = $periods;
            }
        }

        return $own;
    }

    /**
     * A step may read a second period, as asking again reads how long the
     * answer may take; one that does not read fails that step like its own.
     *
     * @param  Closure(): ?int  $period
     * @param  Closure(int): int  $prune
     * @param  list<InvalidConfigurationException>  $failures
     */
    private function apply(Closure $period, Closure $prune, array &$failures): int
    {
        try {
            $days = $period();

            return $days === null ? 0 : $prune($days);
        } catch (InvalidConfigurationException $exception) {
            $failures[] = $exception;

            return 0;
        }
    }

    /**
     * Asks confirmed addresses that have not confirmed or answered for $days
     * to confirm again, each with a link that works for $withinDays. One
     * request per period: the lifecycle claims the cycle conditionally, so a
     * rerun or a second worker asks nobody twice.
     *
     * @param  list<string>  $exceptProjects  projects that follow periods of their own
     */
    public function askAgain(int $days, int $withinDays, ?string $list = null, ?string $project = null, array $exceptProjects = []): int
    {
        $before = TimestampRange::daysAgo($days);

        return static::modelClass()::query()
            ->within($list, $project)
            ->outsideProjects($exceptProjects)
            ->dueForReconfirmation($before)
            ->with('currentSubscription')
            ->lazyById(500)
            ->sum(function (WaitlistEntry $entry) use ($before, $withinDays): int {
                $cycle = $entry->currentSubscription;

                return (int) ($cycle !== null && $this->lifecycle->requestReconfirmation($cycle, Str::random(64), $before, Carbon::now()->addDays($withinDays), RequestContext::none()));
            });
    }

    /**
     * Ends the cycles whose request to confirm again went unanswered until
     * its link expired. The deadline was set with the request, so this needs
     * no period, and a request already out runs its course when asking is
     * turned off. The entry then waits out unsubscribed_days like anyone
     * who left: the consent records of the mails it got stay for a dispute.
     */
    public function lapse(?string $list = null, ?string $project = null): int
    {
        return static::modelClass()::query()
            ->within($list, $project)
            ->unansweredReconfirmation()
            ->with('currentSubscription')
            ->lazyById(500)
            ->sum(function (WaitlistEntry $entry): int {
                $cycle = $entry->currentSubscription;

                return (int) ($cycle !== null && $this->lifecycle->end($cycle, EndReason::Lapsed, RequestContext::none()));
            });
    }

    /**
     * Ends abandoned signups as expired so reporting still sees them, then
     * erases them. A confirmation landing in between wins.
     *
     * @param  list<string>  $exceptProjects  projects that follow periods of their own
     */
    public function expirePending(int $days, ?string $list = null, ?string $project = null, array $exceptProjects = []): int
    {
        $erased = static::modelClass()::query()
            ->within($list, $project)
            ->outsideProjects($exceptProjects)
            ->awaitingConfirmationSince($days)
            ->with('currentSubscription')
            ->lazyById(500)
            ->sum(function (WaitlistEntry $entry): int {
                $cycle = $entry->currentSubscription;

                if ($cycle === null || ! $this->lifecycle->end($cycle, EndReason::Expired, RequestContext::none())) {
                    return 0;
                }

                return (int) ($this->erase)($entry, fn (Builder $query) => $query
                    ->where('latest_subscription_id', $cycle->getKey())
                    ->where('status', EntryStatus::Unsubscribed));
            });

        // Expired but never erased, because a listener failed or the process
        // died in between: these would otherwise wait out unsubscribed_days,
        // which is meant for people who once opted in.
        return $erased + static::modelClass()::query()
            ->within($list, $project)
            ->outsideProjects($exceptProjects)
            ->where('status', EntryStatus::Unsubscribed)
            ->whereHas('latestSubscription', fn (Builder $cycle) => $cycle->where('end_reason', EndReason::Expired))
            ->lazyById(500)
            ->sum(fn (WaitlistEntry $entry): int => (int) ($this->erase)($entry, fn (Builder $query) => $query
                ->where('latest_subscription_id', $entry->latest_subscription_id)
                ->where('status', EntryStatus::Unsubscribed)));
    }

    /**
     * Re-checked under the row lock, so someone who comes back in the
     * meantime is kept.
     *
     * @param  list<string>  $exceptProjects  projects that follow periods of their own
     */
    public function eraseUnsubscribed(int $days, ?string $list = null, ?string $project = null, array $exceptProjects = []): int
    {
        return static::modelClass()::query()
            ->within($list, $project)
            ->outsideProjects($exceptProjects)
            ->leftBefore($days)
            ->lazyById(500)
            ->sum(fn (WaitlistEntry $entry): int => (int) ($this->erase)($entry, fn (Builder $query) => $query
                ->where('latest_subscription_id', $entry->latest_subscription_id)
                ->leftBefore($days)));
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
            ->outsideProjects($exceptProjects)
            ->where('occurred_at', '<', TimestampRange::daysAgo($days))
            ->where(fn (Builder $query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
            ->update(['ip' => null, 'user_agent' => null]);
    }
}
