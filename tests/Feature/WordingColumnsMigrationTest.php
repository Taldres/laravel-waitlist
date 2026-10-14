<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\Waitlist\Config\WaitlistConfig;

/**
 * @return array<string, string> the declared type of each column, such as varchar(255)
 */
function wordingColumnTypes(): array
{
    return collect(Schema::connection(WaitlistConfig::connection())->getColumns('waitlist_wordings'))->pluck('type', 'name')->all();
}

it('leaves the wording columns of a fresh install alone', function () {
    $before = wordingColumnTypes();

    (require __DIR__.'/../../database/migrations/2026_10_14_000002_widen_waitlist_wordings_table.php')->up();

    expect(wordingColumnTypes())->toBe($before);
});

it('widens the wording columns of a table that v0.1.1 created', function () {
    if (DB::connection(WaitlistConfig::connection())->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite keeps no column lengths.');
    }

    Schema::dropIfExists('waitlist_wordings');
    Schema::create('waitlist_wordings', function (Blueprint $table) {
        $table->id();
        $table->string('project')->default('default');
        $table->string('purpose', 100);
        $table->string('version', 100);
        $table->string('locale', 35)->default('');
        $table->text('text');
        $table->string('registered_by')->nullable();
        $table->timestamp('registered_at')->useCurrent();
        $table->timestamp('retired_at')->nullable();

        $table->unique(['project', 'purpose', 'version', 'locale']);
    });

    (require __DIR__.'/../../database/migrations/2026_10_14_000002_widen_waitlist_wordings_table.php')->up();

    $types = wordingColumnTypes();

    expect($types['purpose'])->toContain('(255)')
        ->and($types['version'])->toContain('(255)');
});
