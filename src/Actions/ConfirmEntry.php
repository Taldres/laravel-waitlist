<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class ConfirmEntry
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
    ) {}

    /**
     * Idempotent: a second click returns the confirmed entry, unless
     * waitlist.double_opt_in.invalidate_confirm_token_after_confirmation makes
     * the link single-use. A token whose cycle has ended is invalid, so the
     * link cannot undo an unsubscribe.
     *
     * @throws InvalidTokenException
     * @throws ExpiredTokenException
     */
    public function __invoke(string $plainToken, ?RequestContext $context = null): WaitlistEntry
    {
        $subscription = static::findByToken($plainToken) ?? throw InvalidTokenException::make();

        if ($subscription->isConfirmed()) {
            return $subscription->entry()->firstOrFail();
        }

        if (! $subscription->isOpen()) {
            throw InvalidTokenException::make();
        }

        if ($subscription->hasExpiredToken()) {
            throw ExpiredTokenException::make();
        }

        if (! $this->lifecycle->confirm($subscription, $context ?? RequestContext::none())) {
            // Another request finished first: report its outcome.
            $subscription->refresh();

            if (! $subscription->isConfirmed()) {
                throw InvalidTokenException::make();
            }
        }

        return $subscription->entry()->firstOrFail();
    }

    public static function findByToken(string $plainToken): ?WaitlistSubscription
    {
        return static::subscriptionModelClass()::query()
            ->where('confirm_token_hash', static::modelClass()::hashToken($plainToken))
            ->first();
    }
}
