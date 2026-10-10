<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\Setting;
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

        /** @var WaitlistEntry $entry */
        $entry = $subscription->entry()->firstOrFail();
        $email = $entry->readableEmail();

        if ($email !== null && $this->addressSaturated($entry->project, $email, except: $subscription)) {
            return false;
        }

        return $this->lifecycle->sendConfirmation($subscription, Str::random(64), $context ?? RequestContext::none());
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
        return Setting::integerOrNull(ConfigKey::ResendCooldown->value);
    }

    /**
     * Confirmation requests per cycle; null for no cap.
     */
    public static function maxConfirmations(): ?int
    {
        return Setting::integerOrNull(ConfigKey::MaxConfirmations->value);
    }

    /**
     * Confirmation requests per address and day across a project's
     * unconfirmed lists; null for no cap.
     */
    public static function maxPendingPerAddress(): ?int
    {
        return Setting::integerOrNull(ConfigKey::MaxPendingPerAddress->value);
    }
}
