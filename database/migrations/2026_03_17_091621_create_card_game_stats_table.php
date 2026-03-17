<?php

use App\Jobs\BackfillCardGameStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_game_stats', function (Blueprint $table) {
            $table->id();
            $table->string('oracle_id')->index();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->foreignId('deck_version_id')->constrained('deck_versions')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('kept')->default(0);
            $table->unsignedInteger('seen')->default(0);
            $table->boolean('won');
            $table->timestamps();

            $table->unique(['oracle_id', 'game_id']);
            $table->index('deck_version_id');
        });

        // Backfill existing completed games
        BackfillCardGameStats::dispatch();
    }

    public function down(): void
    {
        Schema::dropIfExists('card_game_stats');
    }
};
