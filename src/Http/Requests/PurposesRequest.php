<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Http\Requests\Concerns\ReadsListFromQuery;

class PurposesRequest extends WaitlistRequest
{
    use ReadsListFromQuery;

    protected function action(): WaitlistAction
    {
        return WaitlistAction::ViewPurposes;
    }

    public function locale(): ?string
    {
        $locale = $this->query('locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}
