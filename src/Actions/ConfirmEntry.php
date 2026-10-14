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
     * Idempotent: a second click returns the confirmed entry. A token whose
     * cycle has ended is invalid, so the link cannot undo an unsubscribe.
     *
     * @throws InvalidTokenException
     * @throws ExpiredTokenException
     */
    public function __invoke(string $plainToken, ?RequestContext $context = null): WaitlistEntry
    {
        $subscription = static::findByToken($plainToken) ?? throw InvalidTokenException::make();

        if ($subscription->isConfirmed()) {
            return $subscription->entry()->first() ?? throw InvalidTokenException::make();
        }

        if (! $subscription->isOpen()) {
            throw InvalidTokenException::make();
        }

        if ($subscription->hasExpiredToken()) {
            throw ExpiredTokenException::make();
        }

        $hash = $subscription->confirm_token_hash;

        if (! $this->lifecycle->confirm($subscription, $context ?? RequestContext::none())) {
            // Another request finished first: report its outcome, read past a
            // snapshot an application's transaction may hold. The entry first,
            // as every step locks it; gone with an erasure, the link is too.
            static::modelClass()::query()->whereKey($subscription->waitlist_entry_id)->lockForUpdate()->first();
            $current = static::subscriptionModelClass()::query()->whereKey($subscription->getKey())->lockForUpdate()->first()
                ?? throw InvalidTokenException::make();
            $subscription->setRawAttributes($current->getAttributes(), sync: true);

            if (! $subscription->isConfirmed()) {
                // Expired only while it is still this link: a resend replaced it otherwise.
                throw $subscription->isOpen() && $subscription->confirm_token_hash === $hash && $subscription->hasExpiredToken()
                    ? ExpiredTokenException::make()
                    : InvalidTokenException::make();
            }
        }

        return $subscription->entry()->first() ?? throw InvalidTokenException::make();
    }

    public static function findByToken(string $plainToken): ?WaitlistSubscription
    {
        return static::subscriptionModelClass()::query()
            ->where('confirm_token_hash', static::modelClass()::hashToken($plainToken))
            ->first();
    }
}
