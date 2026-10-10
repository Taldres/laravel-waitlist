<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Support\LazyCollection;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\Recipient;
use Taldres\Waitlist\Support\ResolvesModel;

class FindRecipients
{
    use ResolvesModel;

    /**
     * Each address once, on a list or across the project. Streamed, so a large
     * list never sits in memory as models.
     *
     * @return LazyCollection<int, Recipient>
     */
    public function __invoke(string $purpose, string $project, ?string $list = null, bool $leavesList = false): LazyCollection
    {
        return LazyCollection::make(function () use ($purpose, $project, $list, $leavesList) {
            $query = static::modelClass()::query()
                ->with('latestSubscription.consents')
                ->within($list, $project)
                ->whereConsentedTo($purpose);

            // By address, not email_hash: until waitlist:rekey has run, one
            // address can sit under hashes of two keys.
            $seen = [];

            foreach ($query->lazyById(500) as $entry) {
                $email = $entry->readableEmail();

                if ($email === null || isset($seen[$key = hash('xxh128', $email)])) {
                    continue;
                }

                $seen[$key] = true;

                yield new Recipient($entry, $email, $purpose, $this->locale($entry, $purpose), $leavesList);
            }
        });
    }

    protected function locale(WaitlistEntry $entry, string $purpose): ?string
    {
        return $entry->latestSubscription?->consents
            ->first(fn (WaitlistConsent $consent): bool => $consent->purpose === $purpose && ! $consent->isWithdrawn())
            ?->locale;
    }
}
