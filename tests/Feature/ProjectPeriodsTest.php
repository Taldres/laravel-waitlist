<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Taldres\Waitlist\Config\TimestampRange;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\ProjectPeriods;
use Taldres\Waitlist\Tests\TestCase;

/**
 * A central API serves sites that promise different periods: acme tells its
 * visitors seven days, the rest of the app keeps the configured thirty.
 */
beforeEach(function () {
    defineDefaultProject();
    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(pendingDays: 7)->confirmLinkLifetime(60 * 24 * 7)));
});

function periodsPendingSince(int $days, string $project, string $email): WaitlistEntry
{
    $entry = WaitlistEntry::factory()->pending()->onList('beta', $project)->create(['email' => $email]);
    $entry->subscriptions()->update(['started_at' => now()->subDays($days)]);

    return $entry;
}

function periodsLeftSince(int $days, string $project, string $email): WaitlistEntry
{
    $entry = WaitlistEntry::factory()->unsubscribed()->onList('beta', $project)->create(['email' => $email]);
    $entry->latestSubscription->forceFill(['ended_at' => now()->subDays($days)])->saveQuietly();

    return $entry;
}

it('knows the periods a project promises, and none for one that promises nothing', function () {
    $catalog = app(ProjectCatalog::class);

    expect($catalog->periods('acme'))->toEqual(new ProjectPeriods(pendingDays: 7, confirmLinkMinutes: 60 * 24 * 7))
        ->and($catalog->periods('default'))->toEqual(new ProjectPeriods)
        ->and($catalog->periods('unknown'))->toEqual(new ProjectPeriods)
        ->and($catalog->periods('acme')->overridesRetention())->toBeTrue()
        ->and($catalog->periods('default')->overridesRetention())->toBeFalse();
});

it('keeps what an earlier call set when the periods are given in several calls', function () {
    $project = (new ProjectDefinition('shop'))->retention(pendingDays: 5)->confirmLinkLifetime(90)->retention(unsubscribedDays: 100);

    expect($project->getPeriods())->toEqual(new ProjectPeriods(pendingDays: 5, unsubscribedDays: 100, confirmLinkMinutes: 90));
});

it('refuses a period that no timestamp holds, when the project is defined', function (Closure $define, string $message) {
    expect($define)->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'a negative number of days' => [fn () => (new ProjectDefinition('shop'))->retention(pendingDays: -1), "A project's pending days must be between 0 and"],
    'days before 1970' => [fn () => (new ProjectDefinition('shop'))->retention(unsubscribedDays: TimestampRange::daysBack() + 1), "A project's unsubscribed days must be between 0 and"],
    'a link that lives no minute' => [fn () => (new ProjectDefinition('shop'))->confirmLinkLifetime(0), "A project's confirm link minutes must be between 1 and"],
    'a link past the last timestamp' => [fn () => (new ProjectDefinition('shop'))->confirmLinkLifetime(TimestampRange::minutesAhead() + 1), "A project's confirm link minutes must be between 1 and"],
]);

it('issues confirm links for the lifetime the project promises, and the configured one elsewhere', function () {
    $this->freezeTime();
    config()->set(ConfigKey::ConfirmTokenTtl->value, 60 * 24 * 30);

    Waitlist::project('acme')->for('beta')->add('user@example.com', waitlistConsent());
    Waitlist::for('beta')->add('user@example.com', waitlistConsent());

    $expiry = fn (string $project): Carbon => WaitlistEntry::query()->where('project', $project)->sole()->currentSubscription->confirm_token_expires_at;

    expect($expiry('acme')->getTimestamp())->toBe(now()->addDays(7)->getTimestamp())
        ->and($expiry('default')->getTimestamp())->toBe(now()->addDays(30)->getTimestamp());
});

it('reads no configured lifetime where the project has its own', function () {
    config()->set(ConfigKey::ConfirmTokenTtl->value, 'soon');

    Waitlist::project('acme')->for('beta')->add('user@example.com', waitlistConsent());

    expect(WaitlistEntry::query()->where('project', 'acme')->sole()->currentSubscription->confirm_token_expires_at)->not->toBeNull();
});

it('prunes a project by its own periods and every other project by the configured ones', function () {
    $acmeStale = periodsPendingSince(10, 'acme', 'acme-stale@example.com');
    $acmeFresh = periodsPendingSince(3, 'acme', 'acme-fresh@example.com');
    $defaultTen = periodsPendingSince(10, 'default', 'default-ten@example.com');
    $defaultOld = periodsPendingSince(40, 'default', 'default-old@example.com');

    expect(Artisan::call('waitlist:prune'))->toBe(0);

    expect(WaitlistEntry::query()->whereKey($acmeStale->getKey())->exists())->toBeFalse()
        ->and(WaitlistEntry::query()->whereKey($acmeFresh->getKey())->exists())->toBeTrue()
        ->and(WaitlistEntry::query()->whereKey($defaultTen->getKey())->exists())->toBeTrue()
        ->and(WaitlistEntry::query()->whereKey($defaultOld->getKey())->exists())->toBeFalse();
});

it('prunes one project by name with its own periods, and leaves the others alone', function () {
    $acmeStale = periodsPendingSince(10, 'acme', 'acme-stale@example.com');
    $defaultOld = periodsPendingSince(40, 'default', 'default-old@example.com');

    Artisan::call('waitlist:prune', ['--project' => 'acme']);

    expect(WaitlistEntry::query()->whereKey($acmeStale->getKey())->exists())->toBeFalse()
        ->and(WaitlistEntry::query()->whereKey($defaultOld->getKey())->exists())->toBeTrue();

    Artisan::call('waitlist:prune', ['--project' => 'default']);

    expect(WaitlistEntry::query()->whereKey($defaultOld->getKey())->exists())->toBeFalse();
});

it('takes the configured period for each one a project leaves out', function () {
    $acmeLeft = periodsLeftSince(500, 'acme', 'acme-left@example.com');
    $acmeRecent = periodsLeftSince(100, 'acme', 'acme-recent@example.com');

    Artisan::call('waitlist:prune');

    expect(WaitlistEntry::query()->whereKey($acmeLeft->getKey())->exists())->toBeTrue()
        ->and(WaitlistEntry::query()->whereKey($acmeRecent->getKey())->exists())->toBeTrue();

    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(pendingDays: 7, unsubscribedDays: 90)));
    Artisan::call('waitlist:prune');

    expect(WaitlistEntry::query()->whereKey($acmeLeft->getKey())->exists())->toBeFalse()
        ->and(WaitlistEntry::query()->whereKey($acmeRecent->getKey())->exists())->toBeFalse();
});

it('clears the request metadata of a project after its own days', function () {
    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->retention(requestMetadataDays: 5)));
    $acme = WaitlistEntry::factory()->pending()->onList('beta', 'acme')->create(['email' => 'a@example.com']);
    $other = WaitlistEntry::factory()->pending()->onList('beta', 'default')->create(['email' => 'b@example.com']);

    foreach ([$acme, $other] as $entry) {
        $entry->activity()->update(['ip' => 'secret-ip', 'user_agent' => 'agent', 'occurred_at' => now()->subDays(10)]);
    }

    Artisan::call('waitlist:prune');

    expect(WaitlistActivity::query()->where('waitlist_entry_id', $acme->getKey())->whereNotNull('ip')->count())->toBe(0)
        ->and(WaitlistActivity::query()->where('waitlist_entry_id', $other->getKey())->whereNotNull('ip')->count())->toBeGreaterThan(0);
});

it('names the periods of a project in the record of processing', function () {
    Artisan::call('waitlist:privacy');

    expect(Artisan::output())
        ->toContain('- Unconfirmed signups erased: after 30 days')
        ->toContain('- The periods above apply to every project except where one of these replaces them:')
        ->toContain('- Unconfirmed signups erased in project [acme]: after 7 days')
        ->not->toContain('Addresses that left erased in project');
});

it('adds nothing to the record where no project differs', function () {
    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project));

    Artisan::call('waitlist:privacy');

    expect(Artisan::output())->not->toContain('except where one of these replaces them');
});
