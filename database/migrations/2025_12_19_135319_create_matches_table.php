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
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->string('token')->index();
            $table->string('mtgo_id')->index();
            $table->foreignIdFor(\App\Models\DeckVersion::class)->nullable()->constrained();
            $table->string('format')->index();
            $table->string('match_type')->index();
            $table->string('result')->nullable()->index();
            $table->integer('games_won')->default(0)->index();
            $table->integer('games_lost')->default(0)->index();
            $table->dateTime('started_at')->index();
            $table->dateTime('ended_at')->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
