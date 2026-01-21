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
        Schema::create('log_events', function (Blueprint $table) {
            $table->id();
            $table->string('file_path', length: 1024);
            $table->bigInteger('byte_offset_start');
            $table->bigInteger('byte_offset_end');
            $table->timestamp('timestamp');
            $table->string('level', length: 8);
            $table->string('category', length: 255);
            $table->string('context', length: 255);
            $table->longText('raw_text');
            $table->dateTime('ingested_at');
            $table->dateTime('processed_at')->nullable();
            $table->string('match_token')->nullable()->index();
            $table->string('game_id')->nullable()->index();
            $table->string('match_id')->nullable()->index();
            $table->string('event_type')->nullable()->index();
            $table->timestamp('logged_at')->index();
            $table->timestamps();
        });

        Schema::table('log_events', function (Blueprint $table) {
            $table->unique(['file_path', 'byte_offset_start'], 'log_events_file_start_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_events');
    }
};
