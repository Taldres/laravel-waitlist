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
     * Guarded, so an application that published the migrations of an earlier
     * release and runs this one again, or publishes it twice, does not fail.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('waitlist_subscriptions', 'reconfirmation_requested_at')) {
            Schema::table('waitlist_subscriptions', function (Blueprint $table) {
                // Set while a confirmed cycle has been asked to confirm again
                // and has not answered; the answer clears it.
                $table->timestamp('reconfirmation_requested_at')->nullable();
            });
        }

        if (! Schema::hasColumn('waitlist_subscriptions', 'reconfirmed_at')) {
            Schema::table('waitlist_subscriptions', function (Blueprint $table) {
                $table->timestamp('reconfirmed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('waitlist_subscriptions')) {
            return;
        }

        foreach (['reconfirmation_requested_at', 'reconfirmed_at'] as $column) {
            if (Schema::hasColumn('waitlist_subscriptions', $column)) {
                Schema::table('waitlist_subscriptions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
