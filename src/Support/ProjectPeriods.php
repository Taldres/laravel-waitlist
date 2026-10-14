<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * The periods a project promises where they differ from waitlist.retention and
 * waitlist.double_opt_in.token_ttl. Null leaves a period to that setting; a
 * project cannot say "never" for one, it gives a longer period instead. So a
 * project can start asking confirmed addresses again, but not stop where the
 * configuration asks them.
 */
final readonly class ProjectPeriods
{
    /**
     * @param  int|null  $pendingDays  days an address may stay unconfirmed
     * @param  int|null  $unsubscribedDays  days an address is kept after it left
     * @param  int|null  $requestMetadataDays  days the IP and user agent stay on the log
     * @param  int|null  $confirmLinkMinutes  minutes a confirm link works
     * @param  int|null  $reconfirmAfterDays  days after confirming, or the last answer, that a confirmed address is asked again
     * @param  int|null  $reconfirmWithinDays  days an address that was asked again has to answer
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public ?int $pendingDays = null,
        public ?int $unsubscribedDays = null,
        public ?int $requestMetadataDays = null,
        public ?int $confirmLinkMinutes = null,
        public ?int $reconfirmAfterDays = null,
        public ?int $reconfirmWithinDays = null,
    ) {
        foreach (['pending days' => $pendingDays, 'unsubscribed days' => $unsubscribedDays, 'request metadata days' => $requestMetadataDays] as $name => $days) {
            self::within($name, $days, 0, TimestampRange::daysBack());
        }

        self::within('confirm link minutes', $confirmLinkMinutes, 1, TimestampRange::minutesAhead());
        self::within('reconfirm after days', $reconfirmAfterDays, 1, TimestampRange::daysBack());
        self::within('reconfirm within days', $reconfirmWithinDays, 1, TimestampRange::daysAhead());
    }

    /**
     * Whether any of the retention periods is the project's own.
     */
    public function overridesRetention(): bool
    {
        return $this->pendingDays !== null || $this->unsubscribedDays !== null || $this->requestMetadataDays !== null
            || $this->reconfirmAfterDays !== null || $this->reconfirmWithinDays !== null;
    }

    private static function within(string $name, ?int $value, int $min, int $max): void
    {
        if ($value !== null && ($value < $min || $value > $max)) {
            throw new InvalidConfigurationException("A project's {$name} must be between {$min} and {$max}, got {$value}.");
        }
    }
}
