<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compiled-match outbox: one row per match, holding the latest
 * `{match}.json` payload and its monotonic file_version. The push loop
 * drains pending rows; last-write-wins on the cloud sink.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outboxes', function (Blueprint $table) {
            $table->id();
            $table->string('match_key')->unique();
            $table->json('payload');
            $table->unsignedInteger('file_version')->default(1);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('synced_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outboxes');
    }
};
