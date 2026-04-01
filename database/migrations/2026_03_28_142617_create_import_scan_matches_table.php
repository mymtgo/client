<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_scan_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_scan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('history_id');
            $table->dateTime('started_at');
            $table->string('opponent');
            $table->string('format');
            $table->string('format_display');
            $table->unsignedInteger('games_won');
            $table->unsignedInteger('games_lost');
            $table->string('outcome');
            $table->string('game_log_token')->nullable();
            $table->float('confidence')->nullable();
            $table->unsignedInteger('round')->default(0);
            $table->string('description')->nullable();
            $table->json('game_ids')->nullable();
            $table->string('local_player')->nullable();
            $table->timestamps();
            $table->index('import_scan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_scan_matches');
    }
};
