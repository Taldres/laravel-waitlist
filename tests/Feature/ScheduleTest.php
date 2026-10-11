<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('leaves scheduling to the application', function () {
    app()->forgetInstance(Schedule::class);

    expect(app(Schedule::class)->events())->toBe([]);
});
