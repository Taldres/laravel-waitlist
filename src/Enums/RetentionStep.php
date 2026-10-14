<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Support\ProjectPeriods;

/**
 * What waitlist:prune does, in the order it does it: asking runs last, so a
 * listener that throws never holds up an erasure.
 *
 * @internal
 */
enum RetentionStep: string
{
    case Expired = 'expired';
    case Erased = 'erased';
    case Cleared = 'cleared';
    case Asked = 'asked';

    /**
     * The configured period in days; null switches the step off.
     *
     * @throws InvalidConfigurationException
     */
    public function configuredDays(): ?int
    {
        return match ($this) {
            self::Expired => WaitlistConfig::pendingDays(),
            self::Erased => WaitlistConfig::unsubscribedDays(),
            self::Cleared => WaitlistConfig::requestMetadataDays(),
            self::Asked => WaitlistConfig::reconfirmAfterDays(),
        };
    }

    /**
     * The period a project promised for itself, if it did.
     */
    public function ownDays(ProjectPeriods $periods): ?int
    {
        return match ($this) {
            self::Expired => $periods->pendingDays,
            self::Erased => $periods->unsubscribedDays,
            self::Cleared => $periods->requestMetadataDays,
            self::Asked => $periods->reconfirmAfterDays,
        };
    }
}
