<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->integer('round');
            $table->integer('login_id');
            $table->string('username')->nullable();
            $table->integer('rank');
            $table->integer('points');
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('draws')->default(0);
            $table->float('opponent_match_win_pct')->nullable();
            $table->float('game_win_pct')->nullable();
            $table->boolean('is_local')->default(false);
            $table->timestamps();
            $table->unique(['challenge_id', 'round', 'login_id']);
            $table->index(['login_id', 'is_local']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_standings');
    }
};
