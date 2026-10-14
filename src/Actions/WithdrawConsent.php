<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class WithdrawConsent
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
    ) {}

    /**
     * @throws InvalidTokenException
     */
    public function __invoke(string $plainToken, string $purpose, ?RequestContext $context = null): WaitlistEntry
    {
        $entry = static::modelClass()::findByUnsubscribeToken($plainToken) ?? throw InvalidTokenException::make();

        return $this->withdraw($entry, $purpose, $context);
    }

    /**
     * Lists that share a primary purpose are separate waitlists; an optional
     * purpose is one consent for the project. So this list ends if the purpose
     * is its primary one, the purpose goes wherever it is optional, and
     * withdrawn as an optional purpose it also ends the lists that exist only
     * for it. Never reaches another project.
     *
     * Decided from the stored consents, so a purpose missing from the catalog
     * can still be withdrawn and a repeat changes nothing. One transaction for
     * all lists: the events wait for the commit, so a failing listener cannot
     * leave the purpose withdrawn on some lists only.
     *
     * @throws InvalidTokenException when the entry was erased meanwhile
     */
    public function withdraw(WaitlistEntry $entry, string $purpose, ?RequestContext $context = null): WaitlistEntry
    {
        $context ??= RequestContext::none();

        $this->forAddress($entry, function (Collection $holders) use ($entry, $purpose, $context): void {
            $self = $holders->first(fn (WaitlistEntry $holder): bool => $holder->is($entry));

            // Erased meanwhile: its primary purpose can no longer be read, and
            // guessing would end the address's other waitlists.
            if ($self === null) {
                return;
            }

            $primaryHere = $this->isPrimaryOf($self, $purpose);

            foreach ($holders as $holder) {
                $cycle = $holder->currentSubscription;
                $consent = $cycle === null ? null : $this->liveConsent($cycle, $purpose);

                if ($cycle === null || $consent === null) {
                    continue;
                }

                if (! $consent->required) {
                    $this->lifecycle->withdraw($consent, $context);
                } elseif ($holder->is($entry) || ! $primaryHere) {
                    $this->lifecycle->end($cycle, EndReason::Unsubscribed, $context);
                }
            }
        });

        // Erased meanwhile: the token names nothing any more.
        return $entry->fresh() ?? throw InvalidTokenException::make();
    }

    /**
     * Runs a step on the address's entries in the entry's project, locked in
     * key order before anything is read: a withdrawal reaches every list of
     * the address, and two that lock them in different orders would deadlock.
     * The entries are found first, outside the transaction, so the reads that
     * decide come after the lock, also under a snapshot that a plain read
     * would fix. An entry erased meanwhile is left out.
     *
     * @internal
     *
     * @template TReturn
     *
     * @param  Closure(Collection<int, WaitlistEntry>): TReturn  $step
     * @return TReturn
     */
    public function forAddress(WaitlistEntry $entry, Closure $step): mixed
    {
        $keys = $this->entriesOfAddress($entry)->modelKeys();

        return static::waitlistConnection()->transaction(function () use ($entry, $keys, $step): mixed {
            $holders = static::modelClass()::query()
                ->whereKey($keys)
                ->orderBy($entry->getKeyName())
                ->lockForUpdate()
                ->get()
                ->load('currentSubscription');

            return $step($holders);
        }, attempts: 3);
    }

    /**
     * Read from the latest cycle, open or ended, so the answer holds after the
     * person has left.
     */
    public function primaryPurposeOf(WaitlistEntry $entry): ?string
    {
        /** @var WaitlistSubscription|null $latest */
        $latest = $entry->latestSubscription()->first();
        $purpose = $latest?->consents()->where('required', true)->value('purpose');

        return is_string($purpose) ? $purpose : null;
    }

    protected function isPrimaryOf(WaitlistEntry $entry, string $purpose): bool
    {
        return $this->primaryPurposeOf($entry) === $purpose;
    }

    protected function liveConsent(WaitlistSubscription $cycle, string $purpose): ?WaitlistConsent
    {
        /** @var WaitlistConsent|null */
        return $cycle->consents()->where('purpose', $purpose)->whereNull('withdrawn_at')->first();
    }

    /**
     * Found by address rather than by email_hash, so entries hashed under
     * another key are included. Without a readable address, or with an email
     * normalizer that does not work, the entry's own hash still reaches the
     * lists hashed with the same key; only entries under another key are
     * missed. A normalizer that does not work is reported.
     *
     * @return Collection<int, WaitlistEntry>
     */
    protected function entriesOfAddress(WaitlistEntry $entry): Collection
    {
        $email = $entry->readableEmail();

        if ($email === null) {
            return $this->entriesOfHash($entry);
        }

        return ConfigFallback::read(
            fn (): Collection => static::modelClass()::query()
                ->inProject($entry->project)
                ->forEmail($email)
                ->get(),
            null,
        ) ?? $this->entriesOfHash($entry);
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    protected function entriesOfHash(WaitlistEntry $entry): Collection
    {
        $entries = static::modelClass()::query()
            ->inProject($entry->project)
            ->where('email_hash', $entry->email_hash)
            ->get();

        // A rekey in another process may have changed the stored hash since
        // the entry was loaded; the token still ends what it names.
        return $entries->contains($entry) ? $entries : $entries->push($entry);
    }
}
