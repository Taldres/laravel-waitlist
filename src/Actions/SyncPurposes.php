<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\Eloquent\Collection;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class SyncPurposes
{
    use ResolvesModel;

    public function __construct(
        protected PurposeRegistry $registry,
        protected SubscriptionLifecycle $lifecycle,
        protected WithdrawConsent $withdraw,
    ) {}

    /**
     * The primary purpose must be part of the set: leaving is an unsubscribe.
     * A purpose left out is withdrawn across the project, like any withdrawal.
     * A no-op for an address without an open cycle.
     *
     * @param  array<string, mixed>  $purposes  purpose => version shown, or {version, locale}
     *
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     * @throws MissingConsentException
     */
    public function __invoke(WaitlistEntry $entry, array $purposes, ?RequestContext $context = null): WaitlistEntry
    {
        $current = $entry->currentSubscription;
        $live = $current?->consents()->whereNull('withdrawn_at')->get() ?? new Collection;

        // Only a purpose that is recorded now has to prove the wording shown, so
        // leaving or keeping purposes does not depend on the hash setting.
        $wanted = $this->registry->resolve(
            $this->registry->policy($entry->project, $entry->list),
            $purposes,
            held: array_map(fn (WaitlistConsent $consent): string => $consent->purpose, $live->all()),
        );

        if ($current === null) {
            return $entry;
        }

        $context ??= RequestContext::none();

        // One submission, one transaction: the events wait for the commit.
        static::waitlistConnection()->transaction(function () use ($entry, $current, $live, $wanted, $context): void {
            foreach ($wanted as $wording) {
                if (! $live->contains('purpose', $wording->purpose)) {
                    $this->lifecycle->grant($current, $wording, $context);
                }
            }

            $keep = array_column($wanted, 'purpose');

            $live
                ->reject(fn (WaitlistConsent $consent) => $consent->required || in_array($consent->purpose, $keep, true))
                ->each(fn (WaitlistConsent $consent) => $this->withdraw->withdraw($entry, $consent->purpose, $context));
        });

        return $entry->refresh();
    }
}
