<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('waitlist.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * For a table that v0.1.1 created, whose purpose and version hold 100
     * characters. A table that already has the wide columns is left alone.
     *
     * MySQL and MariaDB hold 3072 bytes of a unique index, which 255
     * characters each of project, purpose and version no longer fit, so the
     * project shrinks to the 100 characters a project name may have, first.
     */
    public function up(): void
    {
        if (! $this->narrow()) {
            return;
        }

        Schema::table('waitlist_wordings', function (Blueprint $table) {
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->string('project', 100)->default('default')->change();
            }

            $table->string('purpose')->change();
            $table->string('version')->change();
        });
    }

    /**
     * The wide columns stay: narrowing them could cut what they hold.
     */
    public function down(): void {}

    private function narrow(): bool
    {
        if (! Schema::hasTable('waitlist_wordings')) {
            return false;
        }

        $purpose = collect(Schema::getColumns('waitlist_wordings'))->firstWhere('name', 'purpose');

        return is_array($purpose) && str_contains($purpose['type'], '(100)');
    }
};
