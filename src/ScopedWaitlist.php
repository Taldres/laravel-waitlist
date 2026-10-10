<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Macroable;
use Taldres\Waitlist\Actions\EraseEntry;
use Taldres\Waitlist\Actions\ExportPersonalData;
use Taldres\Waitlist\Actions\FindRecipients;
use Taldres\Waitlist\Actions\ForgetEmail;
use Taldres\Waitlist\Actions\RequestManageLink;
use Taldres\Waitlist\Actions\ResendConfirmation;
use Taldres\Waitlist\Actions\SubscribeToWaitlist;
use Taldres\Waitlist\Actions\UnsubscribeEntry;
use Taldres\Waitlist\Actions\WithdrawConsent;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
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
     * Waitlist::unsubscribe($token).
     */
    public function unsubscribe(string $email, ?RequestContext $context = null): ?WaitlistEntry
    {
        $entry = $this->find($email);

        return $entry ? app(UnsubscribeEntry::class)->unsubscribe($entry, $context) : null;
    }

    /**
     * The list's primary purpose ends this list only; an optional purpose is
     * withdrawn on every list of the project and ends the lists that exist only
     * for it.
     */
    public function withdraw(string $email, string $purpose, ?RequestContext $context = null): ?WaitlistEntry
    {
        $entry = $this->find($email);

        return $entry ? app(WithdrawConsent::class)->withdraw($entry, $purpose, $context) : null;
    }

    /**
     * Only for an address that confirmed at least once: anyone can type an
     * address in, and a mailbox that never opted in gets nothing. False when
     * nothing was sent; a public form must not tell the cases apart.
     */
    public function requestManageLink(string $email): bool
    {
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
