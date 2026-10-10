<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ResolvesModel;

class RequestManageLink
{
    use ResolvesModel;

    public function __construct(
        protected IssueManageLink $issue,
    ) {}

    /**
     * Whoever asks never sees the link: it only reaches the mailbox, which is
     * what lets it stand in for a login. Returns false when nothing was sent
     * because the cooldown is running or the address cannot be read; callers
     * that face the public must not reveal which case applied.
     */
    public function __invoke(WaitlistEntry $entry): bool
    {
        if ($entry->readableEmail() === null) {
            return false;
        }

        $cooldown = WaitlistConfig::manageRequestCooldown();
        $now = Carbon::now();

        // Read before the claim below: a link that cannot be issued must not start the cooldown.
        IssueManageLink::ttl();

        if ($cooldown !== null && $this->sentToAddressSince($entry, $now->copy()->subMinutes($cooldown))) {
            return false;
        }

        // A conditional update, so two requests racing for the same entry
        // send one mail.
        $query = static::modelClass()::query()->whereKey($entry->getKey());

        if ($cooldown !== null) {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull('manage_link_sent_at')
                ->orWhere('manage_link_sent_at', '<=', $now->copy()->subMinutes($cooldown)));
        }

        $claimed = $query->update(['manage_link_sent_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        $entry->forceFill(['manage_link_sent_at' => $now])->syncOriginalAttribute('manage_link_sent_at');

        $link = ($this->issue)($entry);

        ManageLinkRequested::dispatch($entry, $link->token, $link->url, $link->expiresAt);

        return true;
    }

    /**
     * The cooldown holds per address across the project's lists, or a request
     * per list would mail the same person once per list.
     */
    protected function sentToAddressSince(WaitlistEntry $entry, Carbon $since): bool
    {
        $email = $entry->readableEmail();

        return $email !== null && static::modelClass()::query()
            ->inProject($entry->project)
            ->forEmail($email)
            ->whereKeyNot($entry->getKey())
            ->where('manage_link_sent_at', '>', $since)
            ->exists();
    }
}
