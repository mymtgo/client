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
        Schema::create('game_log_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('file_path')->unique();
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->string('match_token')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_log_cursors');
    }
};
