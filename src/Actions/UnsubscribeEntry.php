<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Taldres\Waitlist\Enums\EndReason;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class UnsubscribeEntry
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
        protected WithdrawConsent $withdraw,
    ) {}

    /**
     * @throws InvalidTokenException
     */
    public function __invoke(string $plainToken, ?RequestContext $context = null): WaitlistEntry
    {
        $entry = static::modelClass()::findByUnsubscribeToken($plainToken) ?? throw InvalidTokenException::make();

        return $this->unsubscribe($entry, $context);
    }

    /**
     * Leaving a list withdraws its primary purpose: the list ends, and where
     * that purpose is an add-on elsewhere it goes too, so leaving a list that
     * exists for the newsletter stops the newsletter everywhere. Idempotent.
     */
    public function unsubscribe(WaitlistEntry $entry, ?RequestContext $context = null): WaitlistEntry
    {
        $primary = $this->withdraw->primaryPurposeOf($entry);

        if ($primary !== null) {
            return $this->withdraw->withdraw($entry, $primary, $context);
        }

        $current = $entry->currentSubscription;

        if ($current !== null) {
            $this->lifecycle->end($current, EndReason::Unsubscribed, $context ?? RequestContext::none());
        }

        return $entry->refresh();
    }
}
