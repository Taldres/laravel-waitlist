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
        Schema::create('waitlist_consents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('waitlist_subscription_id')
                ->constrained('waitlist_subscriptions')
                ->cascadeOnDelete();
            $table->string('purpose');
            $table->string('version');
            $table->string('locale', 35)->nullable();
            $table->text('text');
            // Stored at grant time, so withdrawing the primary purpose ends the
            // cycle even after a config change.
            $table->boolean('required');
            // Explicit default: MySQL 5.7 and MariaDB before 10.10 otherwise give
            // the first timestamp column ON UPDATE CURRENT_TIMESTAMP, and a
            // withdrawal would rewrite when consent was given.
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('withdrawn_at')->nullable();
            // 1 until withdrawn, then null: at most one live grant per purpose
            // and cycle; a re-grant after a withdrawal gets its own row.
            $table->unsignedTinyInteger('active')->nullable();

            $table->unique(['waitlist_subscription_id', 'purpose', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_consents');
    }
};
