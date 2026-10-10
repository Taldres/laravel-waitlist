<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

enum EntryStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
