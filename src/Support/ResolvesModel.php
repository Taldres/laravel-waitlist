<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

trait ResolvesModel
{
    /**
     * Transactions must run on this connection, not the application default, or
     * a configured waitlist.connection would commit outside them.
     */
    protected static function waitlistConnection(): Connection
    {
        /** @var Connection */
        return DB::connection(WaitlistEntry::waitlistConnection());
    }

    /**
     * @return class-string<WaitlistEntry>
     */
    protected static function modelClass(): string
    {
        return WaitlistConfig::entryModel();
    }

    /**
     * @return class-string<WaitlistSubscription>
     */
    protected static function subscriptionModelClass(): string
    {
        return WaitlistConfig::subscriptionModel();
    }

    /**
     * @return class-string<WaitlistConsent>
     */
    protected static function consentModelClass(): string
    {
        return WaitlistConfig::consentModel();
    }

    /**
     * @return class-string<WaitlistActivity>
     */
    protected static function activityModelClass(): string
    {
        return WaitlistConfig::activityModel();
    }
}
