<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests\Concerns;

use Taldres\Waitlist\Config\WaitlistConfig;

/**
 * For the GET routes, which name their list in the query: a list that is not
 * a string stands for no list at all, and the answer says so.
 */
trait ReadsListFromQuery
{
    public function effectiveList(): string
    {
        $list = $this->query('list');

        return match (true) {
            $list === null || $list === '' => WaitlistConfig::defaultList(),
            is_string($list) => $list,
            default => '',
        };
    }
}
