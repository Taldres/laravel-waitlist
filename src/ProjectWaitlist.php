<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Taldres\Waitlist\Actions\ExportPersonalData;
use Taldres\Waitlist\Actions\FindRecipients;
use Taldres\Waitlist\Actions\ForgetEmail;
use Taldres\Waitlist\Actions\RegisterWording;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Reporting\WaitlistReport;
use Taldres\Waitlist\Support\PersonalData;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\Recipient;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * exists() and findByEmail() match an address in any status, including one that
 * left; use the entry's status or hasConsentFor() for current membership.
 */
class ProjectWaitlist
{
    use Macroable;
    use ResolvesModel;

    public function __construct(
        protected string $project,
    ) {}

    public function name(): string
    {
        return $this->project;
    }

    public function for(string $list): ScopedWaitlist
    {
        return new ScopedWaitlist($list, $this->project);
    }

    /**
     * The wording a signup form should show, primary purpose first, in the
     * given locale where the version has one. Post back each purpose with the
     * version and locale shown.
     *
     * @return list<PurposeWording>
     *
     * @throws UnknownWaitlistException
     */
    public function purposes(string $list, ?string $locale = null): array
    {
        $registry = app(PurposeRegistry::class);

        return $registry->current($registry->policy($this->project, $list), $locale);
    }

    /**
     * For a catalog that reads registered wording (StoredWordingCatalog). The
     * same text again changes nothing; other text for a registered version is
     * refused.
     *
     * @param  string|array<string, string>  $wording
     * @return int texts added or restored
     *
     * @throws InvalidArgumentException when a purpose, version or locale is too long
     * @throws WordingConflictException
     */
    public function registerWording(string $purpose, string $version, string|array $wording): int
    {
        return app(RegisterWording::class)($this->project, $purpose, $version, $wording);
    }

    /**
     * Stops accepting a registered version for new consents.
     *
     * @return int texts retired
     */
    public function retireWording(string $purpose, string $version): int
    {
        return app(RegisterWording::class)->retire($this->project, $purpose, $version);
    }

    /**
     * Everyone a mail for the purpose may go to, on every list of the project,
     * once per address. Their links withdraw that purpose; for a list's primary
     * purpose a link leaves only that list.
     *
     * @return LazyCollection<int, Recipient>
     */
    public function recipients(string $purpose): LazyCollection
    {
        return app(FindRecipients::class)(purpose: $purpose, project: $this->project);
    }

    public function exists(string $email, ?string $list = null): bool
    {
        return $this->emailQuery($email, $list)->exists();
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function findByEmail(string $email, ?string $list = null): Collection
    {
        return $this->emailQuery($email, $list)->get()->toBase();
    }

    /**
     * Deletes the address from the project's lists, or one of them. A request
     * that concerns the person needs Waitlist::allProjects().
     */
    public function forget(string $email, ?string $list = null): int
    {
        return app(ForgetEmail::class)(email: $email, list: $list, project: $this->project);
    }

    /**
     * @return Collection<int, PersonalData>
     */
    public function personalData(string $email, ?string $list = null): Collection
    {
        return app(ExportPersonalData::class)(email: $email, list: $list, project: $this->project);
    }

    public function report(): WaitlistReport
    {
        return (new WaitlistReport)->forProject($this->project);
    }

    /**
     * @return Builder<WaitlistEntry>
     */
    protected function emailQuery(string $email, ?string $list): Builder
    {
        return static::modelClass()::query()->forEmail($email)->within($list, $this->project);
    }
}
