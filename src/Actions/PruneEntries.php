<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PruneResult;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\Setting;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class PruneEntries
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
        protected EraseEntry $erase,
    ) {}

    public function __invoke(?string $list = null, ?string $project = null): PruneResult
    {
        return new PruneResult(
            expired: ($days = static::days(ConfigKey::RetentionPendingDays->value)) === null ? 0 : $this->expirePending($days, $list, $project),
            erased: ($days = static::days(ConfigKey::RetentionUnsubscribedDays->value)) === null ? 0 : $this->eraseUnsubscribed($days, $list, $project),
            cleared: ($days = static::days(ConfigKey::RetentionRequestMetadataDays->value)) === null ? 0 : $this->clearRequestMetadata($days, $list, $project),
        );
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
            ->where('occurred_at', '<', Carbon::now()->subDays($days))
            ->where(fn (Builder $query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
            ->update(['ip' => null, 'user_agent' => null]);
    }

    /**
     * A retention period in days, or null to keep. An empty variable keeps
     * the package default rather than keeping data forever.
     *
     * @param  string  $key  ConfigKey::RetentionPendingDays, RetentionUnsubscribedDays or RetentionRequestMetadataDays, by value
     */
    public static function days(string $key): ?int
    {
        $days = Setting::integerOrNull($key);

        // A negative period moves the cutoff into the future and would erase
        // what was just collected.
        if ($days !== null && $days < 0) {
            throw new InvalidConfigurationException("{$key} must not be negative.");
        }

        return $days;
    }
}
