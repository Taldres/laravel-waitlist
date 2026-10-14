<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models\Concerns;

use Taldres\Waitlist\Config\WaitlistConfig;

/**
 * The waitlist.connection setting names the connection of every package table;
 * unset, the models follow the application's default.
 */
trait UsesWaitlistConnection
{
    public function getConnectionName(): ?string
    {
        return WaitlistConfig::connection() ?? parent::getConnectionName();
    }
}
