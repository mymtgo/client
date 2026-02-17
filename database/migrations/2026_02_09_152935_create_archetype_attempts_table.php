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
        Schema::create('archetype_match_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id')->nullable();
            $table->unsignedBigInteger('player_id')->nullable();
            $table->string('format');
            $table->json('payload');
            $table->integer('status_code')->nullable();
            $table->json('response')->nullable();
            $table->unsignedBigInteger('archetype_id')->nullable();
            $table->float('confidence')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archetype_match_attempts');
    }
};
