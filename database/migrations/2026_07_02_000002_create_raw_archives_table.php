<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index over the keep-forever gzipped raw-log archive (the `archive` disk).
 * One row per capture, append-only — full recompile reach over all history
 * so compilation-layer bugs stay fixable after MTGO rotates its logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_archives', function (Blueprint $table) {
            $table->id();
            $table->string('match_key')->index();
            $table->string('path', 1024);
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('byte_len');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_archives');
    }
};
