<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;

function exportPath(): string
{
    return sys_get_temp_dir().'/waitlist-export-test.csv';
}

it('exports a list to csv and filters by status', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->count(2)->create();
    WaitlistEntry::factory()->pending()->onList('beta')->create();
    WaitlistEntry::factory()->confirmed()->onList('other')->create();

    $this->artisan('waitlist:export', ['list' => 'beta', '--path' => exportPath()])
        ->expectsOutputToContain('3')
        ->assertSuccessful();

    expect(substr_count((string) file_get_contents(exportPath()), "\n"))->toBe(4);

    $this->artisan('waitlist:export', ['list' => 'beta', '--status' => 'confirmed', '--path' => exportPath()])
        ->assertSuccessful();

    expect(substr_count((string) file_get_contents(exportPath()), "\n"))->toBe(3);

    $this->artisan('waitlist:export', ['list' => 'beta', '--status' => 'nonsense'])->assertFailed();
});

it('neutralizes spreadsheet formulas and can be turned off', function () {
    config()->set(ConfigKey::ExportColumns->value, ['email']);

    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => '=1+1@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => '-safe@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'plain@example.com']);

    $this->artisan('waitlist:export', ['list' => 'beta', '--path' => exportPath()])->assertSuccessful();

    $csv = (string) file_get_contents(exportPath());

    expect($csv)->toContain("'=1+1@example.com")
        ->and($csv)->toContain("'-safe@example.com")
        ->and($csv)->toContain('plain@example.com')
        ->and($csv)->not->toContain("'plain@example.com");

    config()->set(ConfigKey::ExportSpreadsheetSafe->value, false);

    $this->artisan('waitlist:export', ['list' => 'beta', '--path' => exportPath()])->assertSuccessful();

    expect((string) file_get_contents(exportPath()))->not->toContain("'=1+1");
});

it('writes csv that RFC 4180 readers parse back', function () {
    config()->set(ConfigKey::ExportColumns->value, ['email', 'metadata']);

    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['metadata' => ['note' => 'She said "hi\\"']]);

    $this->artisan('waitlist:export', ['list' => 'beta', '--path' => exportPath()])->assertSuccessful();

    $rows = array_map(fn (string $line) => str_getcsv($line, escape: ''), file(exportPath(), FILE_IGNORE_NEW_LINES) ?: []);

    expect(json_decode($rows[1][1], true))->toBe(['note' => 'She said "hi\\"']);
});

it('refuses to export a column that is not exportable', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create();

    config()->set(ConfigKey::ExportColumns->value, ['email', 'unsubscribe_token']);

    expect(fn () => Waitlist::for('beta')->export(exportPath()))
        ->toThrow(InvalidArgumentException::class, 'unsubscribe_token');
});

it('prunes stale pending entries', function () {
    Event::fake([EntryForgotten::class]);

    $stale = WaitlistEntry::factory()->pending()->create();
    // started_at is immutable on the model, so backdate it in the database.
    $stale->subscriptions()->update(['started_at' => now()->subDays(40)]);
    WaitlistEntry::factory()->pending()->create();

    $this->artisan('waitlist:prune')
        ->expectsOutputToContain('Expired and erased 1 unconfirmed, erased 0 unsubscribed')
        ->assertSuccessful();

    expect(WaitlistEntry::query()->count())->toBe(1);
    Event::assertDispatchedTimes(EntryForgotten::class, 1);
});

it('erases a whole list only when told to', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->count(2)->create();
    WaitlistEntry::factory()->confirmed()->onList('launch')->create();

    $this->artisan('waitlist:forget', ['--all' => true])->assertFailed();
    $this->artisan('waitlist:forget', ['--all' => true, '--list' => 'beta'])
        ->expectsConfirmation('Permanently erase every entry on [beta]?', 'no')
        ->assertFailed();

    expect(WaitlistEntry::query()->count())->toBe(3);

    $this->artisan('waitlist:forget', ['--all' => true, '--list' => 'beta'])
        ->expectsConfirmation('Permanently erase every entry on [beta]?', 'yes')
        ->expectsOutputToContain('Deleted 2 entries from beta.')
        ->assertSuccessful();

    expect(WaitlistEntry::query()->pluck('list')->all())->toBe(['launch']);
});

it('never erases a whole list unasked or by mistake', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->count(2)->create();

    $this->artisan('waitlist:forget', ['email' => 'user@example.com', '--all' => true, '--list' => 'beta'])->assertFailed();
    $this->artisan('waitlist:forget', ['--all' => true, '--list' => 'beta', '--no-interaction' => true])->assertFailed();

    expect(WaitlistEntry::query()->count())->toBe(2);

    $this->artisan('waitlist:forget', ['--all' => true, '--list' => 'beta', '--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Deleted 2 entries from beta.')
        ->assertSuccessful();

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('says where it looked when a list narrows the search', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta', 'acme')->create(['email' => 'user@example.com']);

    $this->artisan('waitlist:show', ['email' => 'user@example.com', '--list' => 'beta'])
        ->expectsOutputToContain('No entries found for user@example.com on default/beta.')
        ->assertSuccessful();
    $this->artisan('waitlist:forget', ['email' => 'user@example.com', '--list' => 'beta'])
        ->expectsOutputToContain('Deleted 0 entries for user@example.com on default/beta.')
        ->assertSuccessful();
});

it('shows stored values as they are, even when they look like console markup', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string']]));
    subscribeAndCapture('beta', 'user@example.com', metadata: ['source' => '<fg=bogus><info>x</info>']);

    $this->artisan('waitlist:show', ['email' => 'user@example.com', '--json' => true])
        ->expectsOutputToContain('<fg=bogus><info>x</info>')
        ->assertSuccessful();
    $this->artisan('waitlist:show', ['email' => 'user@example.com'])->assertSuccessful();
    $this->artisan('waitlist:show', ['email' => 'user@example.com', '--pretty' => true])
        ->expectsOutputToContain('<fg=bogus><info>x</info>')
        ->assertSuccessful();
});

it('forgets an email, optionally on one list', function () {
    WaitlistEntry::factory()->confirmed()->onList('beta')->create(['email' => 'user@example.com']);
    WaitlistEntry::factory()->confirmed()->onList('launch')->create(['email' => 'user@example.com']);

    $this->artisan('waitlist:forget', ['email' => 'user@example.com', '--list' => 'beta'])->assertSuccessful();

    expect(WaitlistEntry::query()->pluck('list')->all())->toBe(['launch']);

    $this->artisan('waitlist:forget', ['email' => 'user@example.com'])->assertSuccessful();

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('shows personal data as a table, as json and as a summary', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);

    $this->artisan('waitlist:show', ['email' => 'user@example.com'])
        ->expectsOutputToContain('user@example.com')
        ->assertSuccessful();

    $this->artisan('waitlist:show', ['email' => 'user@example.com', '--json' => true])
        ->expectsOutputToContain('"subscriptions"')
        ->doesntExpectOutputToContain('token')
        ->assertSuccessful();

    $this->artisan('waitlist:show', ['email' => 'user@example.com', '--pretty' => true])
        ->expectsOutputToContain('Subscriptions')
        ->expectsOutputToContain('Consent to waitlist (2026-10): Email me when early access opens.')
        ->expectsOutputToContain('Activity')
        ->assertSuccessful();
});

it('reports when nothing is stored', function () {
    $this->artisan('waitlist:show', ['email' => 'missing@example.com'])
        ->expectsOutputToContain('No entries found')
        ->assertSuccessful();
});

it('exports every row exactly once, beyond one chunk and whatever the timestamps say', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);

    foreach (range(1, 520) as $n) {
        Waitlist::subscribe('beta', "user{$n}@example.com", waitlistConsent());
    }

    // An import: the newest ids carry the oldest timestamps.
    WaitlistEntry::query()->orderByDesc('id')->limit(30)->pluck('id')
        ->each(fn (string $id, int $n) => WaitlistEntry::query()->whereKey($id)->update(['created_at' => now()->subYears(2)->addMinutes($n)]));

    $path = tempnam(sys_get_temp_dir(), 'waitlist');
    $rows = Waitlist::for('beta')->export($path);
    $emails = array_column(array_map('str_getcsv', array_slice(file($path, FILE_IGNORE_NEW_LINES), 1)), 2);
    unlink($path);

    expect($rows)->toBe(520)
        ->and(array_unique($emails))->toHaveCount(520);
});
