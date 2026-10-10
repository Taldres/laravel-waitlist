<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Taldres\Waitlist\Enums\ConfigKey;

function scheduledPrune(): ?ScheduledEvent
{
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'waitlist:prune'));
}

it('schedules the retention run by default', function () {
    $event = scheduledPrune();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('follows the configured cron expression', function () {
    config()->set(ConfigKey::RetentionSchedule->value, '0 * * * *');

    expect(scheduledPrune()->expression)->toBe('0 * * * *');
});

it('leaves scheduling to the application when switched off', function () {
    config()->set(ConfigKey::RetentionSchedule->value, null);

    expect(scheduledPrune())->toBeNull();
});
