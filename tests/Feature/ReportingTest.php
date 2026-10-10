<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Taldres\ImmutableAttributes\Exceptions\ImmutableAttributeException;
use Taldres\Waitlist\Actions\RecordActivity;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Reporting\Period;
use Taldres\Waitlist\Tests\TestCase;

it('counts signups and confirmations per day', function () {
    $this->travelTo('2026-03-01 09:00:00');
    $first = subscribeAndCapture('beta', 'one@example.com');
    Waitlist::confirm($first['confirm']);

    $this->travelTo('2026-03-03 09:00:00');
    subscribeAndCapture('beta', 'two@example.com');

    $series = Waitlist::for('beta')->report()->between('2026-03-01', '2026-03-03')->daily();

    expect($series)->toHaveCount(2)
        ->and($series->forDate('2026-03-01')->signups())->toBe(1)
        ->and($series->forDate('2026-03-01')->of(ActivityType::Confirmed))->toBe(1)
        ->and($series->forDate('2026-03-03')->signups())->toBe(1)
        ->and($series->forDate('2026-03-02'))->toBeNull()
        ->and($series->fillGaps())->toHaveCount(3)
        ->and($series->fillGaps()->forDate('2026-03-02')->total())->toBe(0);
});

it('sums a period and reports a confirmation rate', function () {
    $first = subscribeAndCapture('beta', 'one@example.com');
    Waitlist::confirm($first['confirm']);
    subscribeAndCapture('beta', 'two@example.com');

    $totals = Waitlist::for('beta')->report()->since(7)->totals();

    expect($totals->signups())->toBe(2)
        ->and($totals->of(ActivityType::Confirmed))->toBe(1)
        ->and($totals->of(ActivityType::ConfirmationRequested))->toBe(2)
        ->and($totals->confirmationRate())->toBe(0.5);
});

it('returns a null rate instead of dividing by zero', function () {
    expect(Waitlist::report()->since(7)->totals()->confirmationRate())->toBeNull();
});

it('keeps lists apart', function () {
    subscribeAndCapture('beta', 'one@example.com');
    subscribeAndCapture('launch', 'two@example.com');

    expect(Waitlist::for('beta')->report()->totals()->signups())->toBe(1)
        ->and(Waitlist::report()->totals()->signups())->toBe(2)
        ->and(Waitlist::report()->forList('beta', 'launch')->totals()->signups())->toBe(2);
});

it('keeps historical counts intact after an erasure', function () {
    $this->travelTo('2026-03-01 09:00:00');
    $tokens = subscribeAndCapture('beta', 'one@example.com');
    Waitlist::confirm($tokens['confirm']);
    subscribeAndCapture('beta', 'two@example.com');

    $before = Waitlist::for('beta')->report()->between('2026-03-01', '2026-03-01')->totals();

    Waitlist::forget('one@example.com');

    $after = Waitlist::for('beta')->report()->between('2026-03-01', '2026-03-01')->totals();

    expect($after->signups())->toBe($before->signups())
        ->and($after->of(ActivityType::Confirmed))->toBe($before->of(ActivityType::Confirmed))
        ->and($after->of(ActivityType::Erased))->toBe(1);
});

it('counts people separately from events', function () {
    $tokens = subscribeAndCapture('beta', 'one@example.com');
    Waitlist::confirm($tokens['confirm']);
    Waitlist::unsubscribe($tokens['unsubscribe']);
    subscribeAndCapture('beta', 'two@example.com');

    $snapshot = Waitlist::for('beta')->snapshot();

    expect($snapshot->confirmed)->toBe(0)
        ->and($snapshot->pending)->toBe(1)
        ->and($snapshot->unsubscribed)->toBe(1)
        ->and($snapshot->active())->toBe(1)
        ->and($snapshot->total())->toBe(2)
        ->and(Waitlist::for('beta')->report()->totals()->signups())->toBe(2);
});

it('dates activity in the app timezone', function () {
    config()->set('app.timezone', 'Pacific/Auckland');

    // 22:00 UTC is already the next day in Auckland.
    $this->travelTo('2026-03-01 22:00:00');
    subscribeAndCapture('beta', 'one@example.com');

    expect(Waitlist::for('beta')->report()->daily()->days[0]->date->toDateString())->toBe('2026-03-02');
});

it('dates any kind of moment alike, and leaves it as it was', function (Closure $make) {
    config()->set('app.timezone', 'Pacific/Auckland');

    // 22:00 UTC is already the next day in Auckland.
    $moment = $make('2026-03-01 22:00:00.123456', new DateTimeZone('UTC'));
    $before = $moment->format('Y-m-d H:i:s.u e');

    expect(RecordActivity::dateFor($moment))->toBe('2026-03-02')
        ->and($moment->format('Y-m-d H:i:s.u e'))->toBe($before);
})->with([
    'DateTime' => fn (string $time, DateTimeZone $zone) => new DateTime($time, $zone),
    'DateTimeImmutable' => fn (string $time, DateTimeZone $zone) => new DateTimeImmutable($time, $zone),
    'Carbon' => fn (string $time, DateTimeZone $zone) => new Carbon\Carbon($time, $zone),
    'CarbonImmutable' => fn (string $time, DateTimeZone $zone) => new CarbonImmutable($time, $zone),
    "Laravel's Carbon" => fn (string $time, DateTimeZone $zone) => new Illuminate\Support\Carbon($time, $zone),
]);

it('builds periods in the app timezone', function () {
    $period = Period::lastDays(7);

    expect($period->days())->toBe(7)
        ->and($period->dates())->toHaveCount(7)
        ->and($period->to->toDateString())->toBe(now()->toDateString());
});

it('counts today and a given day in the app timezone', function () {
    config()->set('app.timezone', 'Pacific/Auckland');

    $this->travelTo('2026-10-08 20:00:00');
    subscribeAndCapture('beta', 'one@example.com');

    expect(Waitlist::report()->since(1)->totals()->signups())->toBe(1)
        ->and(Waitlist::report()->between(now(), now())->totals()->signups())->toBe(1);
});

it('counts a list in every project when the report spans all projects', function () {
    Waitlist::define('acme', TestCase::defineTestProject(...));

    Waitlist::for('beta')->add('a@example.com', waitlistConsent());
    Waitlist::project('acme')->for('beta')->add('b@example.com', waitlistConsent());

    expect(Waitlist::allProjects()->report()->forList('beta')->totals()->signups())->toBe(2)
        ->and(Waitlist::report()->forList('beta')->totals()->signups())->toBe(1)
        ->and(Waitlist::project('acme')->report()->forList('beta')->totals()->signups())->toBe(1);
});

it('logs the status a departure left, so a clean-up after leaving is no second departure', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);

    $left = Waitlist::for('beta')->add('left@example.com', waitlistConsent())->entry;
    Waitlist::unsubscribe(Waitlist::unsubscribeToken($left)->token);
    Waitlist::for('beta')->forget('left@example.com');

    Waitlist::for('beta')->add('erased@example.com', waitlistConsent());
    Waitlist::for('beta')->forget('erased@example.com');

    config()->set(ConfigKey::DoubleOptIn->value, true);
    $pending = subscribeAndCapture('beta', 'pending@example.com');
    Waitlist::unsubscribe($pending['unsubscribe']);

    $departures = WaitlistActivity::query()->whereNotNull('previous_status')->orderBy('id')->get()
        ->map(fn (WaitlistActivity $row) => "{$row->type->value} from {$row->previous_status?->value}")->all();

    expect($departures)->toBe([
        'unsubscribed from confirmed',
        'erased from unsubscribed',
        'erased from confirmed',
        'unsubscribed from pending',
    ])->and(WaitlistActivity::query()->where('type', '!=', 'unsubscribed')->where('type', '!=', 'erased')->whereNotNull('previous_status')->exists())->toBeFalse();
});

it('tells how many were confirmed at the end of any day', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);

    $this->travelTo('2026-10-01 12:00:00');
    foreach (['a', 'b', 'c'] as $name) {
        Waitlist::for('beta')->add("{$name}@example.com", waitlistConsent());
    }

    $this->travelTo('2026-10-02 12:00:00');
    Waitlist::unsubscribe(Waitlist::unsubscribeToken(Waitlist::for('beta')->find('b@example.com'))->token);

    $this->travelTo('2026-10-03 12:00:00');
    Waitlist::for('beta')->forget('c@example.com');

    $this->travelTo('2026-10-04 12:00:00');
    Waitlist::for('beta')->forget('b@example.com');
    config()->set(ConfigKey::DoubleOptIn->value, true);
    Waitlist::unsubscribe(subscribeAndCapture('beta', 'd@example.com')['unsubscribe']);

    $report = Waitlist::for('beta')->report();

    expect(array_map(fn (string $day) => $report->confirmedOn($day), ['2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']))
        ->toBe([0, 3, 2, 1, 1])
        ->and(Waitlist::for('beta')->snapshot()->confirmed)->toBe(1)
        ->and($report->totals()->of(ActivityType::Erased))->toBe(2)
        ->and($report->totals()->of(ActivityType::Erased, from: EntryStatus::Confirmed))->toBe(1)
        ->and($report->between('2026-10-03', '2026-10-04')->totals()->confirmedChange())->toBe(-1)
        ->and($report->between('2026-10-01', '2026-10-04')->daily()->forDate('2026-10-02')?->leftConfirmed())->toBe(1);
});

it('counts grants and withdrawals of one purpose', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
    Waitlist::confirm($tokens['confirm']);
    Waitlist::withdrawConsent($tokens['unsubscribe'], 'newsletter');

    $totals = Waitlist::for('beta')->report()->forPurpose('newsletter')->totals();

    expect($totals->of(ActivityType::ConsentWithdrawn))->toBe(1)
        ->and($totals->of(ActivityType::Confirmed))->toBe(0)
        ->and(Waitlist::for('beta')->report()->totals()->of(ActivityType::Confirmed))->toBe(1);
});

it('never rewrites recorded activity', function () {
    Waitlist::subscribe('beta', 'user@example.com', waitlistConsent());

    $activity = WaitlistActivity::query()->firstOrFail();
    $activity->list = 'gamma';
    $activity->save();
})->throws(ImmutableAttributeException::class, 'immutable attribute(s) [list]');
