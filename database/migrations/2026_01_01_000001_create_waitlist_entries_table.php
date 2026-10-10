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
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('project')->default('default');
            $table->string('list');
            $table->text('email'); // encrypted
            $table->char('email_hash', 64);
            // Projection of the latest subscription, so reads need no join.
            $table->string('status');
            $table->unsignedBigInteger('latest_subscription_id')->nullable();
            $table->string('unsubscribe_token_hash', 64)->unique();
            // Encrypted; kept so later mails can carry the same link.
            $table->text('unsubscribe_token')->nullable();
            $table->string('manage_token_hash', 64)->nullable()->unique();
            $table->timestamp('manage_token_expires_at')->nullable();
            $table->timestamp('manage_link_sent_at')->nullable();
            $table->text('metadata')->nullable(); // encrypted
            $table->timestamps();

            // email_hash first, so lookups across all lists use it too; project
            // is not nullable because NULLs never collide in a unique index.
            $table->unique(['email_hash', 'project', 'list']);
            $table->index(['project', 'list', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
