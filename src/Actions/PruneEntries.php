<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Models\WaitlistEntry;
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
     * @throws InvalidConfigurationException
     */
    public function __invoke(?string $list = null, ?string $project = null): PruneResult
    {
        $failures = [];

        $result = new PruneResult(
            expired: $this->apply(WaitlistConfig::pendingDays(...), fn (int $days): int => $this->expirePending($days, $list, $project), $failures),
            erased: $this->apply(WaitlistConfig::unsubscribedDays(...), fn (int $days): int => $this->eraseUnsubscribed($days, $list, $project), $failures),
            cleared: $this->apply(WaitlistConfig::requestMetadataDays(...), fn (int $days): int => $this->clearRequestMetadata($days, $list, $project), $failures),
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
     */
    public function expirePending(int $days, ?string $list = null, ?string $project = null): int
    {
        $erased = 0;

        static::modelClass()::query()
            ->within($list, $project)
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
     */
    public function eraseUnsubscribed(int $days, ?string $list = null, ?string $project = null): int
    {
        $erased = 0;

        static::modelClass()::query()
            ->within($list, $project)
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
     */
    public function clearRequestMetadata(int $days, ?string $list = null, ?string $project = null): int
    {
        return static::activityModelClass()::query()
            ->within($list, $project)
            ->where('occurred_at', '<', TimestampRange::daysAgo($days))
            ->where(fn (Builder $query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
            ->update(['ip' => null, 'user_agent' => null]);
    }
}
