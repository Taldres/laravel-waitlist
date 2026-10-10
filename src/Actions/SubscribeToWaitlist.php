<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscribeResult;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class SubscribeToWaitlist
{
    use ResolvesModel;

    public function __construct(
        protected EmailNormalizer $normalizer,
        protected SubscriptionLifecycle $lifecycle,
        protected ResendConfirmation $resend,
        protected PurposeRegistry $registry,
    ) {}

    /**
     * What happens depends on the address:
     *
     * - unknown: a first cycle starts with the given purposes
     * - waiting for confirmation: treated as a resend request
     * - already confirmed: nothing happens
     * - left before: a new cycle starts with the given purposes
     *
     * Purposes are only recorded when a cycle starts. Adding one to a running
     * cycle needs proof of the mailbox, which the manage token provides.
     *
     * New wording sent with a choice stays registered even if the signup
     * fails afterwards.
     *
     * @param  array<string, mixed>  $purposes  purpose => version shown, or {version, locale, hash, text}
     * @param  array<string, mixed>  $metadata
     *
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     * @throws WordingConflictException
     * @throws MissingConsentException
     * @throws InvalidEmailException
     */
    public function __invoke(
        string $list,
        string $email,
        array $purposes,
        array $metadata = [],
        ?RequestContext $context = null,
        string $project = WaitlistEntry::DEFAULT_PROJECT,
    ): SubscribeResult {
        $context ??= RequestContext::none();
        $policy = $this->registry->policy($project, $list);
        // Decided before anything is registered or looked up: a setting that
        // does not read must refuse every signup alike, a known address too.
        $doubleOptIn = $policy->doubleOptIn;
        $wordings = $this->registry->resolve($policy, $purposes, acceptWording: true, caller: $context->caller);

        $email = $this->normalizer->normalize($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmailException::make();
        }

        $metadata = self::scrub($metadata);

        $entry = $this->find($policy, $email);

        if ($entry === null) {
            try {
                return $this->createWithCycle($policy, $doubleOptIn, $email, $metadata, $wordings, $context);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the address first.
                $entry = $this->find($policy, $email) ?? throw new UnknownWaitlistException('The waitlist entry vanished mid-request.');
            }
        }

        return $this->continueExisting($policy, $doubleOptIn, $entry, $email, $metadata, $wordings, $context);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<PurposeWording>  $wordings
     */
    protected function createWithCycle(ListPolicy $policy, bool $doubleOptIn, string $email, array $metadata, array $wordings, RequestContext $context): SubscribeResult
    {
        $model = static::modelClass();
        $deferred = $doubleOptIn && $this->resend->addressSaturated($policy->project, $email);
        $confirmToken = $doubleOptIn && ! $deferred ? Str::random(64) : null;
        $unsubscribeToken = Str::random(64);

        [$entry, $subscription] = static::waitlistConnection()->transaction(function () use ($model, $policy, $doubleOptIn, $email, $metadata, $wordings, $context, $confirmToken, $unsubscribeToken): array {
            /** @var WaitlistEntry $entry */
            $entry = $model::query()->create([
                'project' => $policy->project,
                'list' => $policy->list,
                'email' => $email,
                'status' => SubscriptionLifecycle::initialStatus(),
                'metadata' => $metadata,
                'unsubscribe_token_hash' => $model::hashToken($unsubscribeToken),
                'unsubscribe_token' => $unsubscribeToken,
            ]);

            return [$entry, $this->lifecycle->start($entry, $wordings, $context, $doubleOptIn, $confirmToken)];
        });

        return new SubscribeResult($entry, $subscription, $deferred ? SubscribeOutcome::ConfirmationDeferred : SubscribeOutcome::Started);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<PurposeWording>  $wordings
     */
    protected function continueExisting(ListPolicy $policy, bool $doubleOptIn, WaitlistEntry $entry, string $email, array $metadata, array $wordings, RequestContext $context, bool $retried = false): SubscribeResult
    {
        $current = $entry->currentSubscription;

        if ($current !== null) {
            return $current->isConfirmed()
                ? new SubscribeResult($entry, $current, SubscribeOutcome::AlreadyConfirmed)
                : new SubscribeResult($entry, $current, $this->resend->resend($current, $context)
                    ? SubscribeOutcome::ConfirmationResent
                    : SubscribeOutcome::ResendSuppressed);
        }

        $deferred = $doubleOptIn && $this->resend->addressSaturated($policy->project, $email);
        $confirmToken = $doubleOptIn && ! $deferred ? Str::random(64) : null;

        try {
            $subscription = static::waitlistConnection()->transaction(function () use ($doubleOptIn, $entry, $metadata, $wordings, $context, $confirmToken): WaitlistSubscription {
                $subscription = $this->lifecycle->start($entry, $wordings, $context, $doubleOptIn, $confirmToken);

                // Only a new opt-in brings metadata: a repeat on a running cycle
                // comes from anyone who knows the address and proves nothing.
                // Written before the commit, so the cycle's events carry it.
                if ($metadata !== []) {
                    $entry->forceFill(['metadata' => array_merge($entry->metadata ?? [], $metadata)])->save();
                }

                return $subscription;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request opened the new cycle first; act on theirs,
            // once: a violation that persists is no race.
            if ($retried) {
                throw new UnknownWaitlistException('The waitlist entry could not get a new cycle.');
            }

            return $this->continueExisting($policy, $doubleOptIn, $entry->refresh(), $email, [], $wordings, $context, retried: true);
        }

        return new SubscribeResult($entry, $subscription, $deferred ? SubscribeOutcome::ConfirmationDeferred : SubscribeOutcome::Resubscribed);
    }

    protected function find(ListPolicy $policy, string $email): ?WaitlistEntry
    {
        return static::modelClass()::query()
            ->with(['currentSubscription', 'latestSubscription'])
            ->onList($policy->list, $policy->project)
            ->forEmail($email)
            ->first();
    }

    /**
     * Invalid UTF-8 cannot be JSON-encoded for the encrypted cast. Replaced
     * rather than refused, so new and known addresses get the same answer.
     *
     * @param  array<array-key, mixed>  $values
     * @return ($values is array<string, mixed> ? array<string, mixed> : array<array-key, mixed>)
     */
    private static function scrub(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            $scrubbed[is_string($key) ? mb_scrub($key, 'UTF-8') : $key] = match (true) {
                is_string($value) => mb_scrub($value, 'UTF-8'),
                is_array($value) => self::scrub($value),
                default => $value,
            };
        }

        return $scrubbed;
    }
}
