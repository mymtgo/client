<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->string('name')->nullable();
            $table->string('format')->nullable();
            $table->text('description')->nullable();
            $table->string('tournament_structure')->nullable();
            $table->string('state')->default('awaiting_players');
            $table->integer('current_round')->nullable();
            $table->integer('max_rounds')->nullable();
            $table->integer('player_count')->default(0);
            $table->integer('min_players')->nullable();
            $table->integer('max_players')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('participated')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['format', 'state']);
            $table->index(['participated', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
