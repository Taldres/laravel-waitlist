<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Support\SetupAudit;

function checkOutput(array $arguments = []): array
{
    $exit = Artisan::call('waitlist:check', $arguments);

    return [$exit, Artisan::output()];
}

it('passes where every setting reads and the tables are there', function () {
    [$exit, $output] = checkOutput();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Every setting reads.')
        ->and($output)->toContain('The tables and columns of this version exist.')
        ->and($output)->not->toContain('is not read by this version');
});

it('names every setting that does not read at once, never its value', function () {
    config()->set(ConfigKey::SignupPerMinute->value, 'secret-value-that-must-not-leak-4242');
    config()->set(ConfigKey::RetentionPendingDays->value, 'soon');
    config()->set(ConfigKey::DoubleOptIn->value, 'maybe');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain(ConfigKey::SignupPerMinute->value)
        ->and($output)->toContain(ConfigKey::RetentionPendingDays->value)
        ->and($output)->toContain(ConfigKey::DoubleOptIn->value)
        ->and($output)->not->toContain('secret-value-that-must-not-leak-4242')
        ->and($output)->not->toContain('Every setting reads.');
});

it('reports a config that is no array once', function () {
    config()->set('waitlist', 'oops');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and(substr_count($output, 'must be an array of settings'))->toBe(1);
});

it('warns of settings a published config still holds that this version does not read', function () {
    config()->set('waitlist.urls', ['confirm' => 'https://example.com/confirm?token={token}']);
    config()->set('waitlist.reporting', ['timezone' => 'Europe/Berlin']);
    config()->set('waitlist.retention.old_days', 7);

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(0)
        ->and($output)->toContain('waitlist.urls is not read by this version of the package')
        ->and($output)->toContain('waitlist.reporting is not read')
        ->and($output)->toContain('waitlist.retention.old_days is not read')
        ->and($output)->not->toContain('waitlist.urls.confirm');

    [$strict] = checkOutput(['--strict' => true]);

    expect($strict)->toBe(1);
});

it('lists a group of unknown settings once, and leaves the settings of this version alone', function () {
    config()->set('waitlist.lists', ['beta' => ['purpose' => 'waitlist'], 'launch' => ['purpose' => 'waitlist']]);

    expect(SetupAudit::unknownSettings())->toBe(['waitlist.lists']);
});

it('finds a column that the migrations created after the app ran them', function () {
    Schema::table('waitlist_subscriptions', fn ($table) => $table->dropColumn('confirmation_outcome'));
    Schema::table('waitlist_wordings', fn ($table) => $table->dropColumn('registered_by'));

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The table waitlist_subscriptions lacks the columns confirmation_outcome')
        ->and($output)->toContain('The table waitlist_wordings lacks the columns registered_by')
        ->and(SetupAudit::missingSchema())->toBe(['waitlist_subscriptions' => ['confirmation_outcome'], 'waitlist_wordings' => ['registered_by']]);
});

it('finds a table that does not exist', function () {
    Schema::drop('waitlist_wordings');

    [$exit, $output] = checkOutput();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The table waitlist_wordings does not exist')
        ->and(SetupAudit::missingSchema())->toBe(['waitlist_wordings' => null]);
});

it('knows the columns the migrations create, so the check cannot drift from them', function (string $kind, string $table) {
    expect(Schema::getColumnListing($table))->toEqualCanonicalizing(SetupAudit::COLUMNS[$kind]);
})->with([
    'entries' => ['entry', 'waitlist_entries'],
    'subscriptions' => ['subscription', 'waitlist_subscriptions'],
    'consents' => ['consent', 'waitlist_consents'],
    'activity' => ['activity', 'waitlist_activity'],
    'wordings' => ['wording', 'waitlist_wordings'],
]);

it('adds a section to about that shows a setting that does not read instead of failing', function () {
    Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]);
    $about = json_decode(Artisan::output(), true)['laravel_waitlist'];

    expect($about['settings'])->toBe('every setting reads')
        ->and($about['routes'])->toBe('off')
        ->and($about['double_opt-in'])->toBe('on')
        ->and($about['retention'])->toBe('unconfirmed 30 days, left 1095 days, request metadata 30 days')
        ->and($about['prune_schedule'])->toBe('15 3 * * *');

    config()->set(ConfigKey::RetentionPendingDays->value, 'soon');
    config()->set(ConfigKey::RoutesEnabled->value, 'true');

    Artisan::call('about', ['--only' => 'laravel_waitlist', '--json' => true]);
    $about = json_decode(Artisan::output(), true)['laravel_waitlist'];

    expect($about['settings'])->toBe('1 do not read, run waitlist:check')
        ->and($about['retention'])->toBe('does not read')
        ->and($about['routes'])->toBe('/waitlist');
});
