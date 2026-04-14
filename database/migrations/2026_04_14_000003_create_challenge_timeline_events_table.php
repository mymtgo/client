<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->integer('round')->nullable();
            $table->string('event_type');
            $table->integer('login_id')->nullable();
            $table->string('username')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['challenge_id', 'round']);
            $table->index(['challenge_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_timeline_events');
    }
};
