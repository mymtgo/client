<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1 ingest schema, consolidated from the 0.x `log_instances` / `log_cursors`
 * / `log_events` tables (their final, post-alter shape). The log-parsing core
 * is ported verbatim and depends on this schema, so it is preserved as-is.
 *
 * Dated early so `log_events` exists before later migrations reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('log_instances')) {
            Schema::create('log_instances', function (Blueprint $table) {
                $table->id();
                $table->string('file_path', 1024);
                $table->string('identity_hash', 40)->index();
                $table->unsignedBigInteger('file_ctime')->nullable();
                $table->string('head_hash', 40);
                $table->unsignedBigInteger('anchor_offset')->nullable();
                $table->string('anchor_hash', 40)->nullable();
                $table->string('tail_hash', 40)->nullable();
                $table->string('local_username')->nullable();
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->timestamp('sealed_at')->nullable();
                $table->string('seal_reason')->nullable();
                $table->timestamps();

                $table->index(['file_path', 'sealed_at'], 'log_instances_path_sealed_idx');
            });
        }

        if (! Schema::hasTable('log_cursors')) {
            Schema::create('log_cursors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('log_instance_id')
                    ->unique()
                    ->constrained('log_instances')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('byte_offset')->default(0);
                $table->unsignedBigInteger('last_observed_size')->default(0);
                $table->timestamp('last_advance_at')->nullable();
                $table->unsignedInteger('stuck_ticks')->default(0);
                $table->unsignedBigInteger('verify_anchor_offset')->nullable();
                $table->string('verify_anchor_hash', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('log_events')) {
            Schema::create('log_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('log_instance_id')
                    ->constrained('log_instances')
                    ->cascadeOnDelete();
                $table->string('file_path', 1024);
                $table->bigInteger('byte_offset_start');
                $table->bigInteger('byte_offset_end');
                $table->timestamp('timestamp');
                $table->string('level', 8);
                $table->string('category', 255);
                $table->string('context', 255);
                $table->longText('raw_text');
                $table->dateTime('ingested_at');
                $table->dateTime('processed_at')->nullable();
                $table->string('match_token')->nullable()->index();
                $table->string('game_id')->nullable()->index();
                $table->string('match_id')->nullable()->index();
                $table->string('tournament_token')->nullable()->index();
                $table->string('event_type')->nullable()->index();
                $table->timestamp('logged_at')->index();
                $table->string('username', 20)->nullable()->index();
                $table->timestamps();

                $table->unique(['log_instance_id', 'byte_offset_start'], 'log_events_instance_start_unique');

                // Covering index for the pipeline's hot-path discovery query.
                $table->index(
                    ['event_type', 'processed_at', 'match_token', 'match_id'],
                    'log_events_pipeline_discovery_index',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_events');
        Schema::dropIfExists('log_cursors');
        Schema::dropIfExists('log_instances');
    }
};
