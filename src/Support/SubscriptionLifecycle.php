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
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfirmationOutcome;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Events\SubscriptionExpired;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * Each transition is a conditional update whose WHERE clause restates the
 * expected state. Only an update that affected exactly one row writes activity,
 * moves the entry's status projection and dispatches an event, so concurrent
 * callers see an event fire once.
 *
 * An event is built inside the transaction and dispatched after it: a link
 * that cannot be built undoes the step, rather than leaving it committed and
 * never announced.
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

        [$subscription, $events] = static::waitlistConnection()->transaction(function () use ($entry, $purposes, $context, $doubleOptIn, $confirmToken, $now, $expiresAt): array {
            $last = static::subscriptionModelClass()::query()
                ->where('waitlist_entry_id', $entry->getKey())
                ->max('sequence');
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
                'confirmed_at' => $doubleOptIn ? null : $now,
            ]);

            foreach ($purposes as $wording) {
                $subscription->consents()->create([
                    'purpose' => $wording->purpose,
                    'version' => $wording->version,
                    'locale' => $wording->locale,
                    'text' => $wording->text,
                    'required' => $wording->required,
                    'granted_at' => $now,
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

            ($this->activity)($entry, $sequence === 1 ? ActivityType::Subscribed : ActivityType::Resubscribed, $subscription, $context);

            if (! $doubleOptIn) {
                ($this->activity)($entry, ActivityType::Confirmed, $subscription, $context);
            } elseif ($confirmToken !== null) {
                ($this->activity)($entry, ActivityType::ConfirmationRequested, $subscription, $context);
            }

            return [$subscription, [
                ...(! $doubleOptIn || $confirmToken !== null ? [$this->subscribed($entry, $subscription, $confirmToken, $doubleOptIn, isNewCycle: true)] : []),
                // Without double opt-in the cycle starts confirmed;
                // confirmation listeners must hear about it too.
                ...(! $doubleOptIn ? [$this->confirmed($entry, $subscription)] : []),
            ]];
        });

        foreach ($events as $event) {
            event($event);
        }

        return $subscription;
    }

    /**
     * The listener's report that no mail left. It takes back what the request
     * held, the cooldown and one in the count, and only for the request the
     * snapshot is about, whose confirm link hash is its identity: the count
     * cannot be, as it goes down again. The condition is part of one UPDATE, so
     * of two reports of the same request exactly one counts, and a report that
     * contradicts an earlier one (the mail went out) or is about an earlier
     * request changes nothing. False then, and when the cycle was confirmed or
     * has ended.
     */
    public function confirmationFailed(WaitlistSubscription $snapshot, RequestContext $context, ?string $reference = null): bool
    {
        $request = $snapshot->confirm_token_hash;

        if ($request === null) {
            return false;
        }

        return $this->transition($snapshot, [
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
     * to it by its confirm link hash like a failure. A mail that went out after
     * its failure was reported, as when a failed job is retried, is accepted
     * once more and takes the cooldown and the count back. A repeat, or a report
     * about an earlier request, changes nothing and answers false. The
     * request of a cycle that has ended has no link left to name it.
     */
    public function confirmationMailed(WaitlistSubscription $snapshot, RequestContext $context, string $reference): bool
    {
        $request = $snapshot->confirm_token_hash;

        if ($request === null) {
            return false;
        }

        if ($this->transition($snapshot, [
            'confirmation_outcome' => ConfirmationOutcome::Mailed,
        ], fn (Builder $query): Builder => $query
            ->where('confirm_token_hash', $request)
            ->whereNull('confirmation_outcome'), ActivityType::ConfirmationMailed, $context, reference: $reference)) {
            return true;
        }

        return $this->transition($snapshot, [
            'confirmation_sent_at' => Carbon::now(),
            'confirmation_count' => new Expression('confirmation_count + 1'),
            'confirmation_outcome' => ConfirmationOutcome::Mailed,
        ], fn (Builder $query): Builder => $query
            ->where('confirm_token_hash', $request)
            ->where('confirmation_outcome', ConfirmationOutcome::Failed), ActivityType::ConfirmationMailed, $context, reference: $reference);
    }

    /**
     * False when the cycle was already confirmed or has ended.
     */
    public function confirm(WaitlistSubscription $subscription, RequestContext $context): bool
    {
        return $this->transition($subscription, ['confirmed_at' => Carbon::now()], fn (Builder $query): Builder => $query
            ->whereNull('confirmed_at')
            ->whereNull('ended_at'), ActivityType::Confirmed, $context,
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
        return $this->transition($subscription, [
            'ended_at' => Carbon::now(),
            'end_reason' => $reason->value,
            'active' => null,
            'confirm_token_hash' => null,
            'confirm_token_expires_at' => null,
        ], fn (Builder $query): Builder => $query
            ->whereNull('ended_at')
            // A confirmation that lands before an expiry wins.
            ->when($reason === EndReason::Expired, fn (Builder $query): Builder => $query->whereNull('confirmed_at')),
            $reason->activityType(), $context,
            fn (WaitlistEntry $entry): object => match ($reason) {
                EndReason::Unsubscribed => new EntryUnsubscribed($entry, $subscription),
                EndReason::Expired => new SubscriptionExpired($entry, $subscription),
            });
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
        $expiresAt = $this->confirmTokenExpiresAt($subscription->entry()->firstOrFail()->project, $now);

        return $this->transition($subscription, [
            'confirm_token_hash' => WaitlistEntry::hashToken($confirmToken),
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
            $granted = static::waitlistConnection()->transaction(function () use ($subscription, $wording, $context): ?array {
                $entry = $this->lockOpenCycle($subscription);

                if ($entry === null) {
                    return null;
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

                return [$entry, $consent];
            });
        } catch (UniqueConstraintViolationException) {
            // Caught outside the transaction: Postgres aborts it on the violation.
            return false;
        }

        if ($granted === null) {
            return false;
        }

        ConsentGranted::dispatch($granted[0], $subscription, $granted[1]);

        return true;
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

        /** @var WaitlistSubscription $subscription */
        $subscription = $consent->subscription()->firstOrFail();

        $entry = static::waitlistConnection()->transaction(function () use ($consent, $subscription, $context): ?WaitlistEntry {
            $entry = $this->lockOpenCycle($subscription);

            if ($entry === null) {
                return null;
            }

            $affected = static::consentModelClass()::query()
                ->whereKey($consent->getKey())
                ->whereNull('withdrawn_at')
                ->update(['withdrawn_at' => Carbon::now(), 'active' => null]);

            if ($affected !== 1) {
                return null;
            }

            ($this->activity)($entry, ActivityType::ConsentWithdrawn, $subscription, $context, $consent->purpose);

            return $entry;
        });

        if ($entry === null) {
            return false;
        }

        ConsentWithdrawn::dispatch($entry, $subscription, $consent->refresh());

        return true;
    }

    /**
     * end() updates the same row, so a grant or withdrawal and an unsubscribe
     * cannot interleave.
     */
    protected function lockOpenCycle(WaitlistSubscription $subscription): ?WaitlistEntry
    {
        $open = static::subscriptionModelClass()::query()
            ->whereKey($subscription->getKey())
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->value('waitlist_entry_id');

        return $open === null ? null : static::modelClass()::query()->whereKey($open)->firstOrFail();
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
        $announce = static::waitlistConnection()->transaction(function () use ($subscription, $attributes, $condition, $type, $context, $event, $reference): ?array {
            $affected = $condition(
                static::subscriptionModelClass()::query()->whereKey($subscription->getKey()),
            )->update($attributes + ['updated_at' => Carbon::now()]);

            if ($affected !== 1) {
                return null;
            }

            $subscription->refresh();

            /** @var WaitlistEntry $entry */
            $entry = $subscription->entry()->firstOrFail();

            // Read before project() moves it: the status this step leaves.
            ($this->activity)($entry, $type, $subscription, $context, reference: $reference, previousStatus: $type->isDeparture() ? $entry->status : null);
            $this->project($entry, $subscription);

            return [$event?->__invoke($entry)];
        });

        if ($announce === null) {
            return false;
        }

        if ($announce[0] !== null) {
            event($announce[0]);
        }

        return true;
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
            ->update(['status' => $status->value, 'updated_at' => Carbon::now()]);

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
            confirmUrl: $confirmToken !== null ? app(ConfirmationUrlGenerator::class)->confirmUrl($entry, $confirmToken) : null,
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
