<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Illuminate\Console\Command;
use Taldres\Waitlist\Actions\RekeyEntries;

class RekeyCommand extends Command
{
    protected $signature = 'waitlist:rekey';

    protected $description = 'Re-encrypt stored values and rebuild the lookup hash under the current key, so earlier keys can be retired';

    public function handle(RekeyEntries $rekey): int
    {
        $result = $rekey();

        $this->info("Rewrote {$result->entries} entries and {$result->activity} log rows under the current key.");

        if ($result->unreadable > 0) {
            $this->warn("{$result->unreadable} entries could not be decrypted with any key and were left as they are. Keep the old key until their retention period or forgetAll() erases them.");
        }

        if ($result->duplicates > 0) {
            $this->warn("{$result->duplicates} entries duplicate an address already stored under the new key on the same list; erase one of each with waitlist:forget.");
        }

        return self::SUCCESS;
    }
}
