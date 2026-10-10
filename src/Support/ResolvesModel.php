<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
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
        /** @var class-string<WaitlistEntry> */
        return self::resolveModel(ConfigKey::Model->value, WaitlistEntry::class);
    }

    /**
     * @return class-string<WaitlistSubscription>
     */
    protected static function subscriptionModelClass(): string
    {
        /** @var class-string<WaitlistSubscription> */
        return self::resolveModel(ConfigKey::SubscriptionModel->value, WaitlistSubscription::class);
    }

    /**
     * @return class-string<WaitlistConsent>
     */
    protected static function consentModelClass(): string
    {
        /** @var class-string<WaitlistConsent> */
        return self::resolveModel(ConfigKey::ConsentModel->value, WaitlistConsent::class);
    }

    /**
     * @return class-string<WaitlistActivity>
     */
    protected static function activityModelClass(): string
    {
        /** @var class-string<WaitlistActivity> */
        return self::resolveModel(ConfigKey::ActivityModel->value, WaitlistActivity::class);
    }

    /**
     * @param  class-string  $base
     * @return class-string
     */
    private static function resolveModel(string $key, string $base): string
    {
        $class = config($key, $base);

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new InvalidConfigurationException("The {$key} config must point to a {$base} subclass.");
        }

        return $class;
    }
}
