<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Actions\ExportPersonalData;
use Taldres\Waitlist\Actions\FindRecipients;
use Taldres\Waitlist\Actions\ForgetEmail;
use Taldres\Waitlist\Actions\ImportEntry;
use Taldres\Waitlist\Actions\IssueManageLink;
use Taldres\Waitlist\Actions\RequestManageLink;
use Taldres\Waitlist\Actions\ResendConfirmation;
use Taldres\Waitlist\Actions\SubscribeToWaitlist;
use Taldres\Waitlist\Actions\UnsubscribeEntry;
use Taldres\Waitlist\Actions\WithdrawConsent;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Exceptions\ManageLinksDisabledException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Exceptions\WordingMismatchException;
use Taldres\Waitlist\Exceptions\WordingNotAcceptedException;
use Taldres\Waitlist\Exports\CsvExporter;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Reporting\ListSnapshot;
use Taldres\Waitlist\Reporting\WaitlistReport;
use Taldres\Waitlist\Support\PersonalData;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\Recipient;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscribeResult;

/**
 * find(), has(), count() and entries() include addresses that left; use the
 * entry's status or hasConsentFor() for current membership.
 */
class ScopedWaitlist
{
    use Macroable;
    use ResolvesModel;

    /**
     * publicCount() rounds down to a multiple of this and withholds anything
     * below it, so a small list does not show each signup.
     */
    public const int PUBLIC_COUNT_STEP = 10;

    public const int PUBLIC_COUNT_CACHE_SECONDS = 60;

    public function __construct(
        protected string $list,
        protected string $project = WaitlistEntry::DEFAULT_PROJECT,
    ) {}

    public function list(): string
    {
        return $this->list;
    }

    public function project(): string
    {
        return $this->project;
    }

    /**
     * @param  array<string, string|array{version: string, locale?: string|null, hash?: string, text?: string}>  $purposes  purpose => version shown, or {version, locale, hash, text}
     * @param  array<string, mixed>  $metadata
     *
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     * @throws WordingConflictException
     * @throws MissingConsentException
     * @throws InvalidEmailException
     */
    public function add(string $email, array $purposes, array $metadata = [], ?RequestContext $context = null): SubscribeResult
    {
        return app(SubscribeToWaitlist::class)(
            list: $this->list,
            email: $email,
            purposes: $purposes,
            metadata: $metadata,
            context: $context,
            project: $this->project,
        );
    }

    /**
     * Adds an address that consented elsewhere, recording $source with it.
     * With the time it consented there, it is confirmed without a mail and
     * EntryImported fires; without, it waits for the confirmation mail that
     * EntrySubscribed asks for. Null when the address is on the list already,
     * in any status: nothing changes for it.
     *
     * @param  array<string, string|array{version: string, locale?: string|null, hash?: string}>  $purposes  purpose => version, or {version, locale, hash}
     *
     * @throws InvalidArgumentException for a missing source, a time of consent in the future, or no time on a list without double opt-in
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     * @throws WordingNotAcceptedException when a purpose comes with text
     * @throws WordingMismatchException when a hash is given that does not match
     * @throws MissingConsentException
     * @throws InvalidEmailException
     */
    public function import(string $email, array $purposes, string $source, ?DateTimeInterface $consentedAt = null, ?RequestContext $context = null): ?SubscribeResult
    {
        return app(ImportEntry::class)(
            list: $this->list,
            email: $email,
            purposes: $purposes,
            source: $source,
            consentedAt: $consentedAt,
            context: $context,
            project: $this->project,
        );
    }

    /**
     * @return list<PurposeWording>
     *
     * @throws UnknownWaitlistException
     */
    public function purposes(?string $locale = null): array
    {
        $registry = app(PurposeRegistry::class);

        return $registry->current($registry->policy($this->project, $this->list), $locale);
    }

    /**
     * The fields a signup on this list may carry, with their validation rules:
     * the project's and the list's own, as the HTTP signup applies them. add()
     * trusts its caller and does not apply them; validate with these in your
     * own controllers.
     *
     * @return array<string, mixed> field => rules
     *
     * @throws UnknownWaitlistException
     */
    public function fields(): array
    {
        $catalog = app(ProjectCatalog::class);

        if ($catalog->policy($this->project, $this->list) === null) {
            throw UnknownWaitlistException::forList($this->project, $this->list);
        }

        return $catalog->fields($this->project, $this->list);
    }

    /**
     * Only for an address that is still waiting; null when nothing was
     * dispatched.
     */
    public function resendConfirmation(string $email, ?RequestContext $context = null): ?WaitlistEntry
    {
        return app(ResendConfirmation::class)(list: $this->list, email: $email, context: $context, project: $this->project);
    }

    /**
     * Withdraws the list's primary purpose, which also withdraws that purpose
     * where it is optional elsewhere in the project. For mail links use
     * Waitlist::unsubscribe($token). Null, too, when an erasure removed the
     * address on the way.
     */
    public function unsubscribe(string $email, ?RequestContext $context = null): ?WaitlistEntry
    {
        $entry = $this->find($email);

        try {
            return $entry ? app(UnsubscribeEntry::class)->unsubscribe($entry, $context) : null;
        } catch (InvalidTokenException) {
            return null;
        }
    }

    /**
     * The list's primary purpose ends this list only; an optional purpose is
     * withdrawn on every list of the project and ends the lists that exist only
     * for it. Null, too, when an erasure removed the address on the way.
     */
    public function withdraw(string $email, string $purpose, ?RequestContext $context = null): ?WaitlistEntry
    {
        $entry = $this->find($email);

        try {
            return $entry ? app(WithdrawConsent::class)->withdraw($entry, $purpose, $context) : null;
        } catch (InvalidTokenException) {
            return null;
        }
    }

    /**
     * Only for an address that confirmed at least once: anyone can type an
     * address in, and a mailbox that never opted in gets nothing. False when
     * nothing was sent; a public form must not tell the cases apart.
     *
     * @throws ManageLinksDisabledException before the lookup, so it tells nothing about the address
     */
    public function requestManageLink(string $email): bool
    {
        app(IssueManageLink::class)->assertOffered($this->project);

        $entry = $this->find($email);

        return $entry !== null
            && $entry->subscriptions()->whereNotNull('confirmed_at')->exists()
            && app(RequestManageLink::class)(entry: $entry);
    }

    /**
     * Defaults to the primary purpose, whose links leave this list; an optional
     * purpose's links withdraw that purpose.
     *
     * @return LazyCollection<int, Recipient>
     *
     * @throws UnknownWaitlistException
     */
    public function recipients(?string $purpose = null): LazyCollection
    {
        $primary = app(PurposeRegistry::class)->policy($this->project, $this->list)->primary;
        $purpose ??= $primary;

        return app(FindRecipients::class)(purpose: $purpose, project: $this->project, list: $this->list, leavesList: $purpose === $primary);
    }

    public function find(string $email): ?WaitlistEntry
    {
        return $this->entries()->forEmail($email)->first();
    }

    public function has(string $email): bool
    {
        return $this->find($email) !== null;
    }

    public function count(): int
    {
        return $this->entries()->count();
    }

    /**
     * The confirmed addresses, rounded down to a multiple of 10 and null below
     * 10, cached briefly in the default cache store. Answers whether or not the
     * list publishes its count; that switch is for the HTTP route.
     */
    public function publicCount(): ?int
    {
        $key = 'waitlist:public-count:'.hash('sha256', (string) json_encode([$this->project, $this->list]));
        // Wrapped, as some stores hand a plain number back as a string.
        $cached = Cache::get($key);

        if (is_array($cached) && is_int($cached['count'] ?? null)) {
            return $cached['count'];
        }

        $confirmed = $this->entries()->where('status', EntryStatus::Confirmed->value)->count();
        $rounded = intdiv($confirmed, self::PUBLIC_COUNT_STEP) * self::PUBLIC_COUNT_STEP;

        if ($rounded === 0) {
            return null;
        }

        // Only counts worth showing are stored, so made-up names on a "*" list
        // leave nothing behind in the cache.
        Cache::put($key, ['count' => $rounded], self::PUBLIC_COUNT_CACHE_SECONDS);

        return $rounded;
    }

    /**
     * The entry's place among the confirmed addresses of this list, in the
     * order their current cycles were confirmed, 1 for the earliest; within
     * the same second, the cycle that started first. Null when the entry is
     * not on this list or not confirmed and on it, also when it was erased.
     * Answers whether or not the list publishes positions; that switch is for
     * the HTTP route.
     */
    public function position(WaitlistEntry $entry): ?int
    {
        $entry = $entry->fresh(['latestSubscription']);
        $mine = $entry?->latestSubscription;

        if ($entry === null || $mine === null || $entry->project !== $this->project || $entry->list !== $this->list
            || $entry->status !== EntryStatus::Confirmed || ! $mine->isOpen() || ! $mine->isConfirmed()) {
            return null;
        }

        $entries = $entry->getTable();
        $cycles = $mine->getTable();
        $confirmedAt = $mine->getRawOriginal('confirmed_at');

        $ahead = $this->entries()
            ->where("{$entries}.status", EntryStatus::Confirmed->value)
            ->join($cycles, "{$cycles}.id", '=', "{$entries}.latest_subscription_id")
            ->whereNull("{$cycles}.ended_at")
            ->where(fn (Builder $query) => $query
                ->where("{$cycles}.confirmed_at", '<', $confirmedAt)
                ->orWhere(fn (Builder $query) => $query
                    ->where("{$cycles}.confirmed_at", $confirmedAt)
                    ->where("{$cycles}.id", '<', $mine->getKey())))
            ->count();

        return $ahead + 1;
    }

    /**
     * @return Builder<WaitlistEntry>
     */
    public function entries(): Builder
    {
        return static::modelClass()::query()->onList($this->list, $this->project);
    }

    public function report(): WaitlistReport
    {
        return (new WaitlistReport)->forProject($this->project)->forList($this->list);
    }

    public function snapshot(): ListSnapshot
    {
        return ListSnapshot::forList($this->list, $this->project);
    }

    public function export(string $path): int
    {
        return app(CsvExporter::class)->export(list: $this->list, status: null, path: $path, project: $this->project);
    }

    public function forget(string $email): int
    {
        return app(ForgetEmail::class)(email: $email, list: $this->list, project: $this->project);
    }

    /**
     * Erases the whole list.
     */
    public function forgetAll(): int
    {
        $erase = app(EraseEntry::class);
        $erased = 0;

        $this->entries()->lazyById(500)->each(function (WaitlistEntry $entry) use ($erase, &$erased): void {
            $erased += (int) $erase($entry);
        });

        return $erased;
    }

    /**
     * @return Collection<int, PersonalData>
     */
    public function personalData(string $email): Collection
    {
        return app(ExportPersonalData::class)(email: $email, list: $this->list, project: $this->project);
    }
}
