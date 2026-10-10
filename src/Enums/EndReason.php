<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

enum EndReason: string
{
    case Unsubscribed = 'unsubscribed';
    case Expired = 'expired';

    public function activityType(): ActivityType
    {
        return match ($this) {
            self::Unsubscribed => ActivityType::Unsubscribed,
            self::Expired => ActivityType::Expired,
        };
    }
}
