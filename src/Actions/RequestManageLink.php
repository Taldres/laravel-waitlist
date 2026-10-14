<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\ManageLinksDisabledException;
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
     *
     * @throws ManageLinksDisabledException
     */
    public function __invoke(WaitlistEntry $entry): bool
    {
        // Before the claim below, like the TTL: a refused request must not start the cooldown.
        $this->issue->assertOffered($entry->project);

        $email = $entry->readableEmail();

        if ($email === null) {
            return false;
        }

        $cooldown = WaitlistConfig::manageRequestCooldown();
        $now = Carbon::now();

        // Read before the claim below: a link that cannot be issued must not start the cooldown.
        WaitlistConfig::manageTokenTtl();

        // Found outside the transaction, locked in key order inside it, as a
        // withdrawal does: requests for two lists of the address would
        // otherwise both find the other list unsent and mail twice.
        $keys = static::modelClass()::query()->inProject($entry->project)->forEmail($email)->pluck($entry->getKeyName())
            ->push($entry->getKey())->unique()->values()->all();

        // On the waitlist connection, so the event waits for this commit and
        // not for a transaction the application has open on another one; a
        // link that cannot be built takes the claim back.
        return static::waitlistTransaction(function () use ($entry, $keys, $cooldown, $now): bool {
            // The cooldown is read from the locked rows: a plain read could
            // see a snapshot the application's transaction took before.
            $locked = static::modelClass()::query()->whereKey($keys)->orderBy($entry->getKeyName())->lockForUpdate()->get();

            if (! $locked->contains($entry)) {
                return false;
            }

            if ($cooldown !== null && $this->sentToAddressSince($entry, $locked, $now->copy()->subMinutes($cooldown))) {
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

            $link = ($this->issue)($entry);

            $entry->forceFill(['manage_link_sent_at' => $now])->syncOriginalAttribute('manage_link_sent_at');

            ManageLinkRequested::dispatch($entry, $link->token, $link->url, $link->expiresAt);

            return true;
        });
    }

    /**
     * The cooldown holds per address across the project's lists, or a request
     * per list would mail the same person once per list.
     *
     * @param  Collection<int, WaitlistEntry>  $entries  the address's entries, locked
     */
    protected function sentToAddressSince(WaitlistEntry $entry, Collection $entries, Carbon $since): bool
    {
        return $entries->contains(fn (WaitlistEntry $other): bool => ! $other->is($entry)
            && $other->manage_link_sent_at !== null
            && $other->manage_link_sent_at->gt($since));
    }
}
