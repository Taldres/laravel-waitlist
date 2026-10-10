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
        Schema::create('waitlist_subscriptions', function (Blueprint $table) {
            // Numeric PK, so rows order without a database-specific uuid function.
            $table->bigIncrements('id');
            $table->foreignUuid('waitlist_entry_id')
                ->constrained('waitlist_entries')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            // 1 while the cycle is open, null once it ended. Every supported
            // engine allows repeated nulls in a unique index, so the pair below
            // enforces at most one open cycle per entry.
            $table->unsignedTinyInteger('active')->nullable();

            $table->string('confirm_token_hash', 64)->nullable()->unique();
            $table->timestamp('confirm_token_expires_at')->nullable();

            // Explicit default: MySQL 5.7 and MariaDB before 10.10 otherwise
            // default it to a zero date, which Laravel's strict mode rejects.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->unsignedInteger('confirmation_count')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamps();

            $table->unique(['waitlist_entry_id', 'active']);
            $table->unique(['waitlist_entry_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_subscriptions');
    }
};
