<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per field where the log projection and the sidecar fold
     * disagreed. Game-level fields set game_id; match-level fields leave it
     * null. Agreement percentage per field is the go/no-go for flipping that
     * field's authority flag.
     */
    public function up(): void
    {
        Schema::create('game_field_diffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('game_id')->nullable()->constrained('games')->cascadeOnDelete();
            $table->string('field', 64);
            $table->json('log_value')->nullable();
            $table->json('sidecar_value')->nullable();
            $table->string('chosen_source', 16);
            $table->timestamps();

            $table->unique(['match_id', 'game_id', 'field'], 'game_field_diffs_subject_field_unique');
            $table->index('field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_field_diffs');
    }
};
