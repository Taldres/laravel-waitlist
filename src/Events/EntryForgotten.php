<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries only scalars: the entry is already hard-deleted when this fires.
 */
class EntryForgotten implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $entryId,
        public string $project,
        public string $list,
        /** Null when the address could not be decrypted. */
        public ?string $email,
    ) {}
}
