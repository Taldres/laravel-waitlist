<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

enum EndReason: string
{
    case Unsubscribed = 'unsubscribed';
    case Expired = 'expired';
    /** A confirmed entry that was asked to confirm again and did not answer in time. */
    case Lapsed = 'lapsed';

    public function activityType(): ActivityType
    {
        return match ($this) {
            self::Unsubscribed => ActivityType::Unsubscribed,
            self::Expired => ActivityType::Expired,
            self::Lapsed => ActivityType::Lapsed,
        };
    }
}
