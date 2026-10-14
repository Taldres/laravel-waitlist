<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Actions\GetUnsubscribeToken;
use Taldres\Waitlist\Actions\RecordActivity;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\LinkGenerator;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfirmationOutcome;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryImported;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Events\ReconfirmationRequested;
use Taldres\Waitlist\Events\SubscriptionExpired;
use Taldres\Waitlist\Events\SubscriptionLapsed;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * Each transition is a conditional update whose WHERE clause restates the
 * expected state. Only an update that affected exactly one row writes activity,
 * moves the entry's status projection and dispatches an event, so concurrent
 * callers see an event fire once.
 *
 * An event is built and dispatched inside the transaction: a link that
 * cannot be built undoes the step, rather than leaving it committed and never
 * announced. The events wait for the commit, and dispatched inside they wait
 * for this connection's: dispatched after it, they would wait for whatever
 * transaction the application has open on another connection, and vanish
 * with its rollback.
 *
 * Every step locks the entry row before its cycle row, as an erasure does,
 * so two steps on one address wait for each other instead of deadlocking.
 * The transactions retry a deadlock where they are the outermost one; each
 * attempt reads what it writes anew under the lock.
 */
class SubscriptionLifecycle
{
    use ResolvesModel;

    public function __construct(
        protected RecordActivity $activity,
        protected GetUnsubscribeToken $unsubscribeToken,
    ) {}

    /**
     * The unique index on (waitlist_entry_id, active) rejects a second
     * concurrent open cycle, so callers must be ready for a
     * UniqueConstraintViolationException.
     *
     * With double opt-in but no confirm token, the cycle starts pending
     * without a confirmation request and announces nothing; a later
     * sendConfirmation() issues one.
     *
     * @param  list<PurposeWording>  $purposes
     */
    public function start(
        WaitlistEntry $entry,
        array $purposes,
        RequestContext $context,
        bool $doubleOptIn,
        ?string $confirmToken,
    ): WaitlistSubscription {
        $now = Carbon::now();
        $expiresAt = $confirmToken !== null ? $this->confirmTokenExpiresAt($entry->project, $now) : null;

        return static::waitlistTransaction(function () use ($entry, $purposes, $context, $doubleOptIn, $confirmToken, $now, $expiresAt): WaitlistSubscription {
            $subscription = $this->openCycle($entry, $purposes, $now, $now, confirmed: ! $doubleOptIn, confirmToken: $confirmToken, expiresAt: $expiresAt);

            ($this->activity)($entry, $subscription->sequence === 1 ? ActivityType::Subscribed : ActivityType::Resubscribed, $subscription, $context);

            if (! $doubleOptIn) {
                ($this->activity)($entry, ActivityType::Confirmed, $subscription, $context);
            } elseif ($confirmToken !== null) {
                ($this->activity)($entry, ActivityType::ConfirmationRequested, $subscription, $context);
            }

            if (! $doubleOptIn || $confirmToken !== null) {
                event($this->subscribed($entry, $subscription, $confirmToken, $doubleOptIn, isNewCycle: true));
            }

            // Without double opt-in the cycle starts confirmed;
            // confirmation listeners must hear about it too.
            if (! $doubleOptIn) {
                event($this->confirmed($entry, $subscription));
            }

            return $subscription;
        });
    }

    /**
     * The first cycle of an imported address. With the time the person
     * consented elsewhere, the cycle starts confirmed on the source's word,
     * its consents keep that time, and EntryImported fires instead of
     * EntrySubscribed and EntryConfirmed, so no confirmation or welcome mail
     * goes out. Without it, the cycle starts as a signup with double opt-in
     * would, and EntrySubscribed asks for the confirmation mail. Either way the
     * log records the source with the `imported` step.
     *
     * @param  list<PurposeWording>  $purposes
     */
    public function import(
        WaitlistEntry $entry,
        array $purposes,
        RequestContext $context,
        string $source,
        ?Carbon $consentedAt,
        ?string $confirmToken,
    ): WaitlistSubscription {
        if ($consentedAt === null) {
            return static::waitlistTransaction(function () use ($entry, $purposes, $context, $source, $confirmToken): WaitlistSubscription {
                $subscription = $this->start($entry, $purposes, $context, doubleOptIn: true, confirmToken: $confirmToken);

                ($this->activity)($entry, ActivityType::Imported, $subscription, $context, reference: $source);

                return $subscription;
            });
        }

        $now = Carbon::now();

        return static::waitlistTransaction(function () use ($entry, $purposes, $context, $source, $consentedAt, $now): WaitlistSubscription {
            $subscription = $this->openCycle($entry, $purposes, $now, $consentedAt, confirmed: true, confirmToken: null, expiresAt: null);

            ($this->activity)($entry, $subscription->sequence === 1 ? ActivityType::Subscribed : ActivityType::Resubscribed, $subscription, $context);
            ($this->activity)($entry, ActivityType::Imported, $subscription, $context, reference: $source);
            ($this->activity)($entry, ActivityType::Confirmed, $subscription, $context);

            event(new EntryImported($entry, $subscription, $source));

            return $subscription;
        });
    }

    /**
     * Inside the caller's transaction: locks the entry, creates the cycle and
     * its consents and moves the projection onto it.
     *
     * @param  list<PurposeWording>  $purposes
     */
    protected function openCycle(
        WaitlistEntry $entry,
        array $purposes,
        Carbon $now,
        Carbon $grantedAt,
        bool $confirmed,
        ?string $confirmToken,
        ?Carbon $expiresAt,
    ): WaitlistSubscription {
        // From the locked row, so a retried attempt does not take the
        // values a rolled-back one left in the model for unchanged.
        $locked = static::modelClass()::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
        $entry->setRawAttributes($locked->getAttributes(), sync: true);

        // The latest cycle by its key: a range read for the highest
        // sequence would lock the index gap after it on InnoDB, which
        // holds up the first cycles of other addresses too.
        $last = $locked->latest_subscription_id === null ? null : static::subscriptionModelClass()::query()
            ->whereKey($locked->latest_subscription_id)
            ->lockForUpdate()
            ->value('sequence');
        $sequence = (is_numeric($last) ? (int) $last : 0) + 1;

        /** @var WaitlistSubscription $subscription */
        $subscription = $entry->subscriptions()->create([
            'sequence' => $sequence,
            'active' => 1,
            'confirm_token_hash' => $confirmToken !== null ? $entry::hashToken($confirmToken) : null,
            'confirm_token_expires_at' => $expiresAt,
            'started_at' => $now,
            'confirmation_sent_at' => $confirmToken !== null ? $now : null,
            'confirmation_count' => $confirmToken !== null ? 1 : 0,
            'confirmed_at' => $confirmed ? $now : null,
        ]);

        foreach ($purposes as $wording) {
            $subscription->consents()->create([
                'purpose' => $wording->purpose,
                'version' => $wording->version,
                'locale' => $wording->locale,
                'text' => $wording->text,
                'required' => $wording->required,
                'granted_at' => $grantedAt,
                'active' => 1,
            ]);
        }

        $entry->forceFill([
            'latest_subscription_id' => $subscription->getKey(),
            'status' => $subscription->projectedStatus(),
        ])->save();

        // A returning address arrives with its ended cycle loaded; the
        // events and the caller must see the new one.
        $entry->setRelation('latestSubscription', $subscription)
            ->setRelation('currentSubscription', $subscription);

        return $subscription;
    }

    /**
     * The listener's report that no mail left. It takes back what the request
     * held, the cooldown and one in the count, and only for the request the
     * event is about, whose confirm link is its identity: the count cannot be,
     * as it goes down again, and neither can the subscription model, which a
     * refresh or a queued job's SerializesModels reloads with the latest
     * request. The condition is part of one UPDATE, so
     * of two reports of the same request exactly one counts, and a report that
     * contradicts an earlier one (the mail went out) or is about an earlier
     * request changes nothing. False then, and when the cycle was confirmed or
     * has ended.
     */
    public function confirmationFailed(EntrySubscribed $event, RequestContext $context, ?string $reference = null): bool
    {
        $request = $this->requestOf($event);

        if ($request === null) {
            return false;
        }

        return $this->transition($event->subscription, [
            'confirmation_sent_at' => null,
            'confirmation_count' => new Expression('confirmation_count - 1'),
            'confirmation_outcome' => ConfirmationOutcome::Failed,
        ], fn (Builder $query): Builder => $query
            ->where('confirm_token_hash', $request)
            ->whereNull('confirmation_outcome')
            ->whereNull('confirmed_at')
            ->whereNull('ended_at')
            ->where('confirmation_count', '>', 0), ActivityType::ConfirmationFailed, $context, reference: $reference);
    }

    /**
     * The listener's report that the mail went out, once per request and bound
     * to it by its confirm link like a failure. A mail that went out after
     * its failure was reported, as when a failed job is retried, is accepted
     * once more and takes the cooldown and the count back. A repeat, or a report
     * about an earlier request, changes nothing and answers false. The
     * request of a cycle that has ended has no link left to name it.
     */
    public function confirmationMailed(EntrySubscribed $event, RequestContext $context, string $reference): bool
    {
        $request = $this->requestOf($event);

        if ($request === null) {
            return false;
        }

        if ($this->transition($event->subscription, [
            'confirmation_outcome' => ConfirmationOutcome::Mailed,
        ], fn (Builder $query): Builder => $query
            ->where('confirm_token_hash', $request)
            ->whereNull('confirmation_outcome'), ActivityType::ConfirmationMailed, $context, reference: $reference)) {
            return true;
        }

        return $this->transition($event->subscription, [
            'confirmation_sent_at' => Carbon::now(),
            'confirmation_count' => new Expression('confirmation_count + 1'),
            'confirmation_outcome' => ConfirmationOutcome::Mailed,
        ], fn (Builder $query): Builder => $query
            ->where('confirm_token_hash', $request)
            ->where('confirmation_outcome', ConfirmationOutcome::Failed), ActivityType::ConfirmationMailed, $context, reference: $reference);
    }

    /**
     * The confirm link hash of the request the event announced, null without
     * double opt-in.
     */
    protected function requestOf(EntrySubscribed $event): ?string
    {
        return $event->confirmToken === null ? null : static::modelClass()::hashToken($event->confirmToken);
    }

    /**
     * False when the cycle was already confirmed or has ended, or when the
     * confirm link it was read with has since been rotated or has expired: a
     * resend between the lookup and this update must leave only the newest
     * link working.
     */
    public function confirm(WaitlistSubscription $subscription, RequestContext $context): bool
    {
        $now = Carbon::now();

        return $this->transition($subscription, ['confirmed_at' => $now], fn (Builder $query): Builder => $query
            ->whereNull('confirmed_at')
            ->whereNull('ended_at')
            ->where('confirm_token_hash', $subscription->confirm_token_hash)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('confirm_token_expires_at')
                ->orWhere('confirm_token_expires_at', '>=', $now)), ActivityType::Confirmed, $context,
            fn (WaitlistEntry $entry): EntryConfirmed => $this->confirmed($entry, $subscription));
    }

    /**
     * The project's own lifetime when it has one, else the configured one, which
     * is then the only setting read.
     */
    protected function confirmTokenExpiresAt(string $project, Carbon $from): ?Carbon
    {
        $minutes = app(ProjectCatalog::class)->periods($project)->confirmLinkMinutes;

        return $minutes === null ? WaitlistConfig::confirmTokenExpiresAt($from) : $from->copy()->addMinutes($minutes);
    }

    /**
     * Clearing `active` releases the unique slot, so a new cycle can start.
     * False when the cycle had already ended.
     */
    public function end(WaitlistSubscription $subscription, EndReason $reason, RequestContext $context): bool
    {
        $now = Carbon::now();

        return $this->transition($subscription, [
            'ended_at' => $now,
            'end_reason' => $reason->value,
            'active' => null,
            'confirm_token_hash' => null,
            'confirm_token_expires_at' => null,
        ], fn (Builder $query): Builder => $query
            ->whereNull('ended_at')
            // A confirmation that lands before an expiry wins.
            ->when($reason === EndReason::Expired, fn (Builder $query): Builder => $query->whereNull('confirmed_at'))
            // So does an answer that lands before a lapse, which clears the
            // request, and one that can still land: the link has not expired.
            ->when($reason === EndReason::Lapsed, fn (Builder $query): Builder => $query
                ->whereNotNull('confirmed_at')
                ->whereNotNull('reconfirmation_requested_at')
                ->where('confirm_token_expires_at', '<', $now)),
            $reason->activityType(), $context,
            fn (WaitlistEntry $entry): object => match ($reason) {
                EndReason::Unsubscribed => new EntryUnsubscribed($entry, $subscription),
                EndReason::Expired => new SubscriptionExpired($entry, $subscription),
                EndReason::Lapsed => new SubscriptionLapsed($entry, $subscription),
            });
    }

    /**
     * Asks a confirmed cycle to confirm again with a fresh confirm link that
     * works until $answerBy. The condition restates that nothing proved
     * interest since $before and that no request is out, so a cycle is asked
     * once per period however often pruning runs or retries. False when the
     * cycle ended or was asked or answered meanwhile.
     *
     * The request counts as the cycle's first confirmation request again, so
     * the resend cooldown and cap apply to it as they did to the signup's.
     */
    public function requestReconfirmation(WaitlistSubscription $subscription, string $confirmToken, Carbon $before, Carbon $answerBy, RequestContext $context): bool
    {
        $now = Carbon::now();
        // As stored, so the event and the API name the same moment.
        $answerBy = $answerBy->copy()->startOfSecond();

        return $this->transition($subscription, [
            'confirm_token_hash' => static::modelClass()::hashToken($confirmToken),
            'confirm_token_expires_at' => $answerBy,
            'reconfirmation_requested_at' => $now,
            'confirmation_sent_at' => $now,
            'confirmation_count' => 1,
            'confirmation_outcome' => null,
        ], fn (Builder $query): Builder => $query
            ->whereNotNull('confirmed_at')
            ->whereNull('ended_at')
            ->whereNull('reconfirmation_requested_at')
            ->where(fn (Builder $query): Builder => $query
                ->where('reconfirmed_at', '<', $before)
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereNull('reconfirmed_at')
                    ->where('confirmed_at', '<', $before))),
            ActivityType::ReconfirmationRequested, $context,
            fn (WaitlistEntry $entry): ReconfirmationRequested => $this->reconfirmationRequested($entry, $subscription, $confirmToken, $answerBy));
    }

    /**
     * Mails an unanswered request to confirm again once more, with a fresh
     * link and the same deadline, for a mail that never arrived. Bound like a
     * resend to the expected confirmation_count, so two of them collapse into
     * one; false once the request was answered, the link expired or the cycle
     * ended.
     */
    public function resendReconfirmation(WaitlistSubscription $subscription, string $confirmToken, RequestContext $context): bool
    {
        $expected = $subscription->confirmation_count;
        $now = Carbon::now();

        return $this->transition($subscription, [
            'confirm_token_hash' => static::modelClass()::hashToken($confirmToken),
            'confirmation_sent_at' => $now,
            'confirmation_count' => $expected + 1,
        ], fn (Builder $query): Builder => $query
            ->whereNotNull('confirmed_at')
            ->whereNull('ended_at')
            ->whereNotNull('reconfirmation_requested_at')
            ->where('confirm_token_expires_at', '>=', $now)
            ->where('confirmation_count', $expected),
            ActivityType::ReconfirmationRequested, $context,
            fn (WaitlistEntry $entry): ReconfirmationRequested => $this->reconfirmationRequested($entry, $subscription, $confirmToken, $subscription->confirm_token_expires_at ?? $now));
    }

    /**
     * The answer to a request to confirm again: the period starts anew. Like a
     * confirmation, it names the link it was looked up with and its expiry,
     * so a link that expired or that a lapse cleared changes nothing. False
     * then, and when another request answered first. Announces nothing: the
     * address simply stays.
     */
    public function reconfirm(WaitlistSubscription $subscription, RequestContext $context): bool
    {
        $now = Carbon::now();

        return $this->transition($subscription, [
            'reconfirmed_at' => $now,
            'reconfirmation_requested_at' => null,
        ], fn (Builder $query): Builder => $query
            ->whereNotNull('confirmed_at')
            ->whereNull('ended_at')
            ->whereNotNull('reconfirmation_requested_at')
            ->where('confirm_token_hash', $subscription->confirm_token_hash)
            ->where('confirm_token_expires_at', '>=', $now), ActivityType::Reconfirmed, $context);
    }

    /**
     * The expected confirmation_count is part of the condition, so two resends
     * that read the same row collapse into one, which a timestamp comparison
     * would not do within the same second.
     */
    public function sendConfirmation(WaitlistSubscription $subscription, string $confirmToken, RequestContext $context): bool
    {
        $expected = $subscription->confirmation_count;
        $now = Carbon::now();
        $entry = $subscription->entry()->first();

        // Erased meanwhile: nobody left to mail.
        if ($entry === null) {
            return false;
        }

        $expiresAt = $this->confirmTokenExpiresAt($entry->project, $now);

        return $this->transition($subscription, [
            'confirm_token_hash' => static::modelClass()::hashToken($confirmToken),
            'confirm_token_expires_at' => $expiresAt,
            'confirmation_sent_at' => $now,
            'confirmation_count' => $expected + 1,
            'confirmation_outcome' => null,
        ], fn (Builder $query): Builder => $query
            ->whereNull('confirmed_at')
            ->whereNull('ended_at')
            ->where('confirmation_count', $expected), ActivityType::ConfirmationRequested, $context,
            // The first request of a cycle that started without one is no reminder.
            fn (WaitlistEntry $entry): EntrySubscribed => $this->subscribed($entry, $subscription, $confirmToken, doubleOptIn: true, isNewCycle: $expected === 0));
    }

    /**
     * False when the cycle ended or the purpose is already granted.
     */
    public function grant(WaitlistSubscription $subscription, PurposeWording $wording, RequestContext $context): bool
    {
        try {
            return static::waitlistTransaction(function () use ($subscription, $wording, $context): bool {
                $entry = $this->lockOpenCycle($subscription);

                if ($entry === null) {
                    return false;
                }

                /** @var WaitlistConsent $consent */
                $consent = $subscription->consents()->create([
                    'purpose' => $wording->purpose,
                    'version' => $wording->version,
                    'locale' => $wording->locale,
                    'text' => $wording->text,
                    'required' => $wording->required,
                    'granted_at' => Carbon::now(),
                    'active' => 1,
                ]);

                ($this->activity)($entry, ActivityType::ConsentGranted, $subscription, $context, $wording->purpose);

                ConsentGranted::dispatch($entry, $subscription, $consent);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Caught outside the transaction: Postgres aborts it on the violation.
            return false;
        }
    }

    /**
     * False when the consent was already withdrawn, the cycle ended, or the
     * purpose is the primary one (withdrawing it ends the cycle).
     */
    public function withdraw(WaitlistConsent $consent, RequestContext $context): bool
    {
        if ($consent->required) {
            return false;
        }

        /** @var WaitlistSubscription|null $subscription */
        $subscription = $consent->subscription()->first();

        if ($subscription === null) {
            return false;
        }

        return static::waitlistTransaction(function () use ($consent, $subscription, $context): bool {
            $entry = $this->lockOpenCycle($subscription);

            if ($entry === null) {
                return false;
            }

            $affected = static::consentModelClass()::query()
                ->whereKey($consent->getKey())
                ->whereNull('withdrawn_at')
                ->update(['withdrawn_at' => Carbon::now(), 'active' => null]);

            if ($affected !== 1) {
                return false;
            }

            ($this->activity)($entry, ActivityType::ConsentWithdrawn, $subscription, $context, $consent->purpose);

            ConsentWithdrawn::dispatch($entry, $subscription, $consent->refresh());

            return true;
        });
    }

    /**
     * end() updates the same cycle row, so a grant or withdrawal and an
     * unsubscribe cannot interleave. The entry first, like every step.
     */
    protected function lockOpenCycle(WaitlistSubscription $subscription): ?WaitlistEntry
    {
        $entry = $this->lockEntry($subscription);

        if ($entry === null) {
            return null;
        }

        $open = static::subscriptionModelClass()::query()
            ->whereKey($subscription->getKey())
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->value($subscription->getKeyName());

        return $open === null ? null : $entry;
    }

    /**
     * Null once the entry was erased.
     */
    protected function lockEntry(WaitlistSubscription $subscription): ?WaitlistEntry
    {
        return static::modelClass()::query()
            ->whereKey($subscription->waitlist_entry_id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * False when another request got there first.
     *
     * @param  array<string, mixed>  $attributes
     * @param  Closure(Builder<WaitlistSubscription>): Builder<WaitlistSubscription>  $condition
     * @param  (Closure(WaitlistEntry): object)|null  $event  null for a step that announces nothing
     */
    protected function transition(
        WaitlistSubscription $subscription,
        array $attributes,
        Closure $condition,
        ActivityType $type,
        RequestContext $context,
        ?Closure $event = null,
        ?string $reference = null,
    ): bool {
        return static::waitlistTransaction(function () use ($subscription, $attributes, $condition, $type, $context, $event, $reference): bool {
            $entry = $this->lockEntry($subscription);

            if ($entry === null) {
                return false;
            }

            $affected = $condition(
                static::subscriptionModelClass()::query()->whereKey($subscription->getKey()),
            )->update($attributes);

            if ($affected !== 1) {
                return false;
            }

            $subscription->refresh();

            // Read before project() moves it: the status this step leaves.
            ($this->activity)($entry, $type, $subscription, $context, reference: $reference, previousStatus: $type->isDeparture() ? $entry->status : null);
            $this->project($entry, $subscription);

            if ($event !== null) {
                event($event($entry));
            }

            return true;
        });
    }

    /**
     * The pointer condition keeps a slow actor working on an older cycle from
     * overwriting a newer one.
     */
    protected function project(WaitlistEntry $entry, WaitlistSubscription $subscription): void
    {
        $status = $subscription->projectedStatus();

        $applied = static::modelClass()::query()
            ->whereKey($entry->getKey())
            ->where('latest_subscription_id', $subscription->getKey())
            ->update(['status' => $status->value]);

        if ($applied === 1) {
            $entry->setAttribute('status', $status);
            $entry->syncOriginalAttribute('status');
        }
    }

    protected function confirmed(WaitlistEntry $entry, WaitlistSubscription $subscription): EntryConfirmed
    {
        $unsubscribe = ($this->unsubscribeToken)($entry);

        return new EntryConfirmed(
            entry: $entry,
            subscription: $subscription,
            unsubscribeToken: $unsubscribe->token,
            unsubscribeUrl: $unsubscribe->url,
        );
    }

    protected function reconfirmationRequested(WaitlistEntry $entry, WaitlistSubscription $subscription, string $confirmToken, Carbon $answerBy): ReconfirmationRequested
    {
        $unsubscribe = ($this->unsubscribeToken)($entry);

        return new ReconfirmationRequested(
            entry: $entry,
            subscription: $subscription,
            confirmToken: $confirmToken,
            confirmUrl: app(LinkGenerator::class)->confirmUrl($entry, $confirmToken),
            unsubscribeToken: $unsubscribe->token,
            unsubscribeUrl: $unsubscribe->url,
            answerBy: $answerBy,
        );
    }

    protected function subscribed(
        WaitlistEntry $entry,
        WaitlistSubscription $subscription,
        ?string $confirmToken,
        bool $doubleOptIn,
        bool $isNewCycle,
    ): EntrySubscribed {
        $unsubscribe = ($this->unsubscribeToken)($entry);

        return new EntrySubscribed(
            entry: $entry,
            subscription: $subscription,
            confirmToken: $confirmToken,
            unsubscribeToken: $unsubscribe->token,
            confirmUrl: $confirmToken !== null ? app(LinkGenerator::class)->confirmUrl($entry, $confirmToken) : null,
            unsubscribeUrl: $unsubscribe->url,
            requiresConfirmation: $doubleOptIn,
            isNewCycle: $isNewCycle,
        );
    }

    public static function initialStatus(): EntryStatus
    {
        return EntryStatus::Pending;
    }
}
