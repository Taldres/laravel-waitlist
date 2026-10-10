<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use Taldres\Waitlist\Actions\ExportPersonalData;
use Taldres\Waitlist\Actions\ForgetEmail;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Reporting\WaitlistReport;
use Taldres\Waitlist\Support\PersonalData;
use Taldres\Waitlist\Support\ResolvesModel;

/**
 * All projects belong to one controller, so an access or erasure request
 * covers all of them.
 */
class AllProjectsWaitlist
{
    use Macroable;
    use ResolvesModel;

    public function exists(string $email): bool
    {
        return $this->emailQuery($email)->exists();
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function findByEmail(string $email): Collection
    {
        return $this->emailQuery($email)->get()->toBase();
    }

    public function forget(string $email): int
    {
        return app(ForgetEmail::class)(email: $email);
    }

    /**
     * @return Collection<int, PersonalData>
     */
    public function personalData(string $email): Collection
    {
        return app(ExportPersonalData::class)(email: $email);
    }

    public function report(): WaitlistReport
    {
        return new WaitlistReport;
    }

    /**
     * @return Builder<WaitlistEntry>
     */
    protected function emailQuery(string $email): Builder
    {
        return static::modelClass()::query()->forEmail($email);
    }
}
