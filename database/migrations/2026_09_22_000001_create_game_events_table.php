<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw sidecar event stream. Append-only, one row per NDJSON line.
     * `seq` restarts per file, so `(log_instance_id, seq)` is the identity
     * and `(session_started_at, seq)` is the fold order across files.
     */
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('log_instance_id')->constrained('log_instances')->cascadeOnDelete();
            $table->string('session', 64);
            $table->dateTime('session_started_at', 3);
            $table->unsignedInteger('seq');
            $table->string('source', 32)->default('mtgo_sidecar');
            $table->string('type', 64);
            $table->dateTime('ts', 3);
            $table->string('game_mtgo_id', 32)->nullable();
            $table->string('match_mtgo_id', 32)->nullable();
            $table->boolean('verified')->default(false);
            $table->json('data');
            $table->json('ref')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->unique(['log_instance_id', 'seq'], 'game_events_instance_seq_unique');
            $table->index(['game_mtgo_id', 'session_started_at', 'seq'], 'game_events_game_fold_idx');
            $table->index('match_mtgo_id');
            $table->index(['type', 'processed_at']);
            // Leading processed_at so RunPipeline's sweep for unprojected
            // sidecar events is a seek on a short NULL run, not a table scan.
            $table->index(['processed_at', 'match_mtgo_id'], 'game_events_unprocessed_idx');
            $table->index(['type', 'session', 'seq'], 'game_events_probe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
