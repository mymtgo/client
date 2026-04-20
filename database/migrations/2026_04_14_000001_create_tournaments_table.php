<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('format')->nullable();
            $table->text('description')->nullable();
            $table->string('tournament_structure')->nullable();
            $table->string('state')->default('AwaitingPlayers');
            $table->unsignedInteger('current_round')->nullable();
            $table->unsignedInteger('max_rounds')->nullable();
            $table->unsignedInteger('player_count')->default(0);
            $table->unsignedInteger('min_players')->nullable();
            $table->unsignedInteger('max_players')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('participated')->default(false);
            $table->string('type')->nullable();
            $table->unsignedBigInteger('event_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['format', 'state']);
            $table->index(['participated', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
