<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscriptionLifecycle;

class GrantConsent
{
    use ResolvesModel;

    public function __construct(
        protected SubscriptionLifecycle $lifecycle,
        protected PurposeRegistry $registry,
    ) {}

    /**
     * Takes a manage token, which stands in for the double opt-in because it
     * only reaches the person through their mailbox, and briefly. The
     * unsubscribe token in every mail cannot add anything.
     *
     * @throws InvalidTokenException
     * @throws ExpiredTokenException
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     */
    public function __invoke(string $plainToken, string $purpose, string $version, ?string $locale = null, ?RequestContext $context = null): WaitlistEntry
    {
        $entry = static::modelClass()::findByManageToken($plainToken) ?? throw InvalidTokenException::make();

        if ($entry->hasExpiredManageToken()) {
            throw ExpiredTokenException::make();
        }

        return $this->grant($entry, $purpose, $version, $locale, $context);
    }

    /**
     * Idempotent: a purpose already granted, or an address with no open cycle,
     * is returned untouched.
     *
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     */
    public function grant(WaitlistEntry $entry, string $purpose, string $version, ?string $locale = null, ?RequestContext $context = null): WaitlistEntry
    {
        $wording = $this->registry->wording($this->registry->policy($entry->project, $entry->list), $purpose, $version, $locale);
        $current = $entry->currentSubscription;

        if ($current !== null) {
            $this->lifecycle->grant($current, $wording, $context ?? RequestContext::none());
        }

        return $entry->refresh();
    }
}
