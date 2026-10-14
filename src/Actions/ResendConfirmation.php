<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\BlindIndex;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class ResendConfirmation
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
    ) {}

    /**
     * Returns the entry when a mail was dispatched, null otherwise: unknown
     * address, nothing pending, cooldown, or a cap (including
     * max_pending_per_address). The cases are deliberately indistinguishable
     * so a public endpoint reveals nothing.
     */
    public function __invoke(string $list, string $email, ?RequestContext $context = null, string $project = WaitlistEntry::DEFAULT_PROJECT): ?WaitlistEntry
    {
        $entry = static::modelClass()::query()
            ->with('currentSubscription')
            ->onList($list, $project)
            ->forEmail($email)
            ->first();

        $current = $entry?->currentSubscription;

        if ($entry === null || $current === null) {
            return null;
        }

        return $this->resend($current, $context) ? $entry : null;
    }

    /**
     * The confirm token is rotated, so only the newest mail can confirm. The
     * unsubscribe token and the cycle's start are untouched, which keeps
     * unsubscribe links already sent working and stops repeated resends from
     * extending the retention period. Also sends the request a cycle started
     * without, once the address is under max_pending_per_address.
     */
    public function resend(WaitlistSubscription $subscription, ?RequestContext $context = null): bool
    {
        if ($subscription->isConfirmed() || ! $subscription->isOpen() || $this->throttled($subscription)) {
            return false;
        }

        $entry = $subscription->entry()->first();

        // Erased meanwhile: nobody left to mail.
        if ($entry === null) {
            return false;
        }

        $email = $entry->readableEmail();

        if ($email === null) {
            return $this->lifecycle->sendConfirmation($subscription, Str::random(64), $context ?? RequestContext::none());
        }

        return $this->countingFor($entry->project, $email, fn (bool $mayCount): bool => $mayCount
            && ! $this->addressSaturated($entry->project, $email, except: $subscription)
            && $this->lifecycle->sendConfirmation($subscription, Str::random(64), $context ?? RequestContext::none()));
    }

    /**
     * Runs a step that counts with addressSaturated() and then sends, while
     * no other request does the same for the address: parallel signups on
     * different lists would otherwise all count below the cap and all send.
     * The lock is held until the step has committed, so the next one counts
     * it. Uses the application's cache store, which must support atomic locks
     * shared by all servers.
     *
     * The step gets false when the lock stays taken; it must then send
     * nothing, so a busy address answers like a saturated one. Inside a
     * transaction the application holds on the waitlist connection, the lock
     * would be gone before that commit and protect nothing, so none is taken.
     *
     * @internal
     *
     * @template TReturn
     *
     * @param  Closure(bool $mayCount): TReturn  $step
     * @return TReturn
     */
    public function countingFor(string $project, string $email, Closure $step): mixed
    {
        if (static::maxPendingPerAddress() === null || static::waitlistConnection()->transactionLevel() > 0) {
            return $step(true);
        }

        // Keyed by the blind index, so the cache never sees the address.
        $key = 'waitlist:pending:'.hash('sha256', $project."\0".BlindIndex::hash($email));

        // Long enough for a step whose database retries wait on other locks;
        // a crashed holder only holds the address's mails back that long.
        $lock = Cache::lock($key, 60);

        // Only the wait is caught: a timeout of the application's own inside
        // the step must not run a step that has committed a second time.
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return $step(false);
        }

        try {
            return $step(true);
        } finally {
            $lock->release();
        }
    }

    /**
     * Counted across the project's unconfirmed lists, since the per-cycle caps
     * alone let anyone send one confirmation mail per list name, and a "*"
     * list accepts any name. Only the last day counts, so a stranger cannot
     * lock the address out of confirming for long.
     */
    public function addressSaturated(string $project, string $email, ?WaitlistSubscription $except = null): bool
    {
        $cap = static::maxPendingPerAddress();

        if ($cap === null) {
            return false;
        }

        $entries = static::modelClass()::query()->inProject($project)->forEmail($email);

        $waiting = static::subscriptionModelClass()::query()
            ->whereNull('ended_at')
            ->whereNull('confirmed_at')
            ->where('confirmation_sent_at', '>=', Carbon::now()->subDay())
            ->whereIn('waitlist_entry_id', $entries->select($entries->getModel()->getKeyName()));

        if ($except !== null) {
            $waiting->whereKeyNot($except->getKey());
        }

        return $waiting->count() >= $cap;
    }

    protected function throttled(WaitlistSubscription $subscription): bool
    {
        $max = static::maxConfirmations();

        // Past the cap, one more may go out once the last link has expired:
        // otherwise anyone could use the cap up and leave the address unable
        // to confirm or sign up again until the cycle is pruned.
        if ($max !== null && $subscription->confirmation_count >= $max && ! $subscription->hasExpiredToken()) {
            return true;
        }

        $cooldown = static::cooldown();

        if ($cooldown === null || $subscription->confirmation_sent_at === null) {
            return false;
        }

        return $subscription->confirmation_sent_at->copy()->addMinutes($cooldown)->isFuture();
    }

    /**
     * Minutes between two confirmation requests of a cycle; null for none.
     */
    public static function cooldown(): ?int
    {
        return WaitlistConfig::resendCooldown();
    }

    /**
     * Confirmation requests per cycle; null for no cap.
     */
    public static function maxConfirmations(): ?int
    {
        return WaitlistConfig::maxConfirmations();
    }

    /**
     * Confirmation requests per address and day across a project's
     * unconfirmed lists; null for no cap.
     */
    public static function maxPendingPerAddress(): ?int
    {
        return WaitlistConfig::maxPendingPerAddress();
    }
}
