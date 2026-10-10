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

    public function up(): void
    {
        Schema::create('waitlist_wordings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project')->default('default');
            // Short enough for the unique index on MySQL with utf8mb4.
            $table->string('purpose', 100);
            $table->string('version', 100);
            // Empty, not null, for a version with one text for every locale:
            // NULLs never collide in the unique index.
            $table->string('locale', 35)->default('');
            $table->text('text');
            $table->string('registered_by')->nullable();
            // Explicit default: MySQL 5.7 and MariaDB before 10.10 otherwise give
            // the first timestamp column ON UPDATE CURRENT_TIMESTAMP, and retiring
            // a version would rewrite when it was registered.
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamp('retired_at')->nullable();

            $table->unique(['project', 'purpose', 'version', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_wordings');
    }
};
