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
        Schema::create('waitlist_activity', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Both keys are nulled on erasure (by the database or
            // RecordActivity::erased()); project, list, type, purpose and
            // occurred_on survive, which keeps historical counts intact.
            $table->foreignUuid('waitlist_entry_id')->nullable()
                ->constrained('waitlist_entries')
                ->nullOnDelete();
            $table->foreignId('waitlist_subscription_id')->nullable()
                ->constrained('waitlist_subscriptions')
                ->nullOnDelete();
            $table->string('project')->default('default');
            $table->string('list');
            $table->string('type');
            // The entry's status right before a departure, so confirmed entries
            // can be counted on any day and an erasure after leaving is not
            // counted as leaving twice.
            $table->string('previous_status')->nullable();
            $table->string('purpose')->nullable();
            // What the app reports it sent (template version, message id). Cleared
            // on erasure: a message id leads back to the person through the mail
            // provider's logs.
            $table->text('reference')->nullable(); // encrypted
            $table->text('ip')->nullable(); // encrypted
            $table->text('user_agent')->nullable(); // encrypted
            // Cleared on erasure: a precise timestamp can single out a person
            // (a date can too, in a small list).
            $table->timestamp('occurred_at')->nullable();
            // In app.timezone; a stored date keeps reports free of
            // engine-specific date functions.
            $table->date('occurred_on');

            $table->index(['project', 'list', 'occurred_on', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_activity');
    }
};
