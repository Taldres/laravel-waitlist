<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Models\WaitlistActivity;

/**
 * @extends Factory<WaitlistActivity>
 */
class WaitlistActivityFactory extends Factory
{
    protected $model = WaitlistActivity::class;

    public function definition(): array
    {
        return [
            'project' => 'default',
            'list' => 'default',
            'type' => ActivityType::Subscribed,
            'occurred_at' => now(),
            'occurred_on' => now()->toDateString(),
        ];
    }

    public function type(ActivityType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['occurred_on' => $date, 'occurred_at' => $date.' 12:00:00']);
    }
}
