<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\Eloquent\Collection;
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
     */
    public function withdraw(WaitlistEntry $entry, string $purpose, ?RequestContext $context = null): WaitlistEntry
    {
        $context ??= RequestContext::none();

        static::waitlistConnection()->transaction(function () use ($entry, $purpose, $context): void {
            $primaryHere = $this->isPrimaryOf($entry, $purpose);

            foreach ($this->entriesOfAddress($entry) as $holder) {
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

        return $entry->refresh();
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
     * another key are included. Without a readable address only the entry
     * itself can be reached.
     *
     * @return Collection<int, WaitlistEntry>
     */
    protected function entriesOfAddress(WaitlistEntry $entry): Collection
    {
        $email = $entry->readableEmail();

        if ($email === null) {
            return new Collection([$entry]);
        }

        return static::modelClass()::query()
            ->with('currentSubscription')
            ->inProject($entry->project)
            ->forEmail($email)
            ->get();
    }
}
