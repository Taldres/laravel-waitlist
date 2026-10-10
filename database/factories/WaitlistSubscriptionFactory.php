<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;

/**
 * @extends Factory<WaitlistSubscription>
 */
class WaitlistSubscriptionFactory extends Factory
{
    protected $model = WaitlistSubscription::class;

    public function definition(): array
    {
        return [
            'waitlist_entry_id' => WaitlistEntry::factory(),
            'sequence' => 1,
            'active' => 1,
            'confirm_token_hash' => WaitlistEntry::hashToken(Str::random(64)),
            'confirm_token_expires_at' => now()->addDays(7),
            'started_at' => now(),
            'confirmation_sent_at' => now(),
            'confirmation_count' => 1,
        ];
    }
}
