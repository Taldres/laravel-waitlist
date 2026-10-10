<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\WaitlistManager;

/**
 * One address a mail for a purpose may go to, with the links it must carry.
 */
final readonly class Recipient
{
    public function __construct(
        public WaitlistEntry $entry,
        public string $email,
        public string $purpose,
        /** The locale the consent was given in; null for wording with one text. */
        public ?string $locale,
        /** True for a mail about one list: its links leave that list. */
        public bool $leavesList,
    ) {}

    public function unsubscribeUrl(): ?string
    {
        return app(WaitlistManager::class)->unsubscribeUrl($this->entry, $this->leavesList ? null : $this->purpose);
    }

    /**
     * @return array<string, string>
     */
    public function listUnsubscribeHeaders(): array
    {
        return app(WaitlistManager::class)->listUnsubscribeHeaders($this->entry, $this->leavesList ? null : $this->purpose);
    }
}
