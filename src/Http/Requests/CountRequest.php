<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Http\Requests\Concerns\ReadsListFromQuery;

class CountRequest extends WaitlistRequest
{
    use ReadsListFromQuery;

    protected function action(): WaitlistAction
    {
        return WaitlistAction::ViewCount;
    }

    /**
     * The names a signup accepts, so a made-up one never reaches the cache key.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'list' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:'.ProjectDefinition::NAME_PATTERN],
        ];
    }
}
