<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingMismatchException;
use Taldres\Waitlist\Exceptions\WordingNotAcceptedException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscribeResult;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class ImportEntry
{
    use ResolvesModel;

    public const int SOURCE_MAX_LENGTH = 255;

    public function __construct(
        protected EmailNormalizer $normalizer,
        protected SubscriptionLifecycle $lifecycle,
        protected ResendConfirmation $resend,
        protected PurposeRegistry $registry,
    ) {}

    /**
     * Adds an address that consented elsewhere. With $consentedAt, the time it
     * consented there, the entry is confirmed without a mail; without it, the
     * entry waits for the confirmation mail EntrySubscribed asks for. An
     * address already on the list, in any status, is left alone, so nobody
     * is duplicated and nobody who left comes back. An address erased since an
     * earlier run is unknown again, so the same records imported again bring
     * it back.
     *
     * Only versions the catalog holds are accepted, never text; a hash, when
     * given, must match.
     *
     * @param  array<array-key, string|array{version: string, locale?: string|null, hash?: string}>  $purposes  purpose => version, or {version, locale, hash}
     * @return SubscribeResult|null null for an address already on the list
     *
     * @throws InvalidArgumentException for a missing source, a time of consent in the future, or no time on a list without double opt-in
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     * @throws WordingNotAcceptedException
     * @throws WordingMismatchException
     * @throws MissingConsentException
     * @throws InvalidEmailException
     */
    public function __invoke(
        string $list,
        string $email,
        array $purposes,
        string $source,
        ?DateTimeInterface $consentedAt = null,
        ?RequestContext $context = null,
        string $project = WaitlistEntry::DEFAULT_PROJECT,
    ): ?SubscribeResult {
        $context ??= RequestContext::none();
        $source = trim($source);

        if ($source === '' || mb_strlen($source) > self::SOURCE_MAX_LENGTH) {
            throw new InvalidArgumentException('An import names its source, in at most '.self::SOURCE_MAX_LENGTH.' characters.');
        }

        // Stored as wall-clock time in the application's zone, like every
        // other timestamp.
        $consentedAt = $consentedAt === null ? null : Carbon::instance($consentedAt)->setTimezone(date_default_timezone_get());

        if ($consentedAt !== null && ($consentedAt->isFuture() || $consentedAt->getTimestamp() < 1)) {
            throw new InvalidArgumentException('The time of consent lies in the future or before 1970.');
        }

        $policy = $this->registry->policy($project, $list);

        if ($consentedAt === null && ! $policy->doubleOptIn) {
            throw new InvalidArgumentException("The list [{$list}] has no double opt-in, so an import needs the time each address consented.");
        }

        $wordings = $this->wordings($policy, $purposes);
        $email = $this->normalizer->normalize($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmailException::make();
        }

        if (static::modelClass()::query()->onList($policy->list, $policy->project)->forEmail($email)->exists()) {
            return null;
        }

        $deferred = $consentedAt === null && $this->resend->addressSaturated($policy->project, $email);
        $confirmToken = $consentedAt === null && ! $deferred ? Str::random(64) : null;
        $unsubscribeToken = Str::random(64);
        $model = static::modelClass();

        try {
            [$entry, $subscription] = static::waitlistTransaction(function () use ($model, $policy, $email, $wordings, $context, $source, $consentedAt, $confirmToken, $unsubscribeToken): array {
                /** @var WaitlistEntry $entry */
                $entry = $model::query()->create([
                    'project' => $policy->project,
                    'list' => $policy->list,
                    'email' => $email,
                    'status' => SubscriptionLifecycle::initialStatus(),
                    'unsubscribe_token_hash' => $model::hashToken($unsubscribeToken),
                    'unsubscribe_token' => $unsubscribeToken,
                ]);

                return [$entry, $this->lifecycle->import($entry, $wordings, $context, $source, $consentedAt, $confirmToken)];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Caught outside the transaction, which Postgres aborts on the
            // violation. A listener's own violation after the commit is no
            // race: the entry is ours then.
            if ($model::query()->where('unsubscribe_token_hash', $model::hashToken($unsubscribeToken))->exists()) {
                throw $exception;
            }

            // A signup or another import added the address meanwhile.
            return null;
        }

        return new SubscribeResult($entry, $subscription, $deferred ? SubscribeOutcome::ConfirmationDeferred : SubscribeOutcome::Started);
    }

    /**
     * Unlike a signup, no hash is asked for: there was no form whose copy of
     * the text it could prove. One that is given must match, as on a signup.
     *
     * @param  array<array-key, mixed>  $purposes
     * @return list<PurposeWording>
     *
     * @throws UnknownPurposeException
     * @throws WordingMismatchException
     * @throws MissingConsentException
     */
    protected function wordings(ListPolicy $policy, array $purposes): array
    {
        $wordings = [];

        foreach ($purposes as $purpose => $choice) {
            if (is_array($choice) && array_key_exists('text', $choice)) {
                throw WordingNotAcceptedException::fromClient((string) $purpose);
            }

            $version = is_array($choice) ? ($choice['version'] ?? '') : $choice;
            $locale = is_array($choice) ? ($choice['locale'] ?? null) : null;
            $hash = is_array($choice) ? ($choice['hash'] ?? null) : null;

            $wording = $this->registry->wording(
                $policy,
                (string) $purpose,
                is_string($version) || is_int($version) ? (string) $version : '',
                is_string($locale) ? $locale : null,
            );

            if (is_string($hash) && ! hash_equals($wording->hash(), strtolower($hash))) {
                throw WordingMismatchException::forPurpose($wording->purpose, $wording->version);
            }

            $wordings[] = $wording;
        }

        if (! in_array($policy->primary, array_column($wordings, 'purpose'), true)) {
            throw MissingConsentException::forPurpose($policy->primary);
        }

        return $wordings;
    }
}
