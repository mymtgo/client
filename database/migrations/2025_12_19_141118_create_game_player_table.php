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
        Schema::create('game_player', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Game::class)->constrained();
            $table->foreignIdFor(\App\Models\Player::class)->constrained();
            $table->unsignedInteger('instance_id')->index();
            $table->boolean('is_local')->default(false)->index();
            $table->boolean('on_play')->default(false)->index();
            $table->unsignedInteger('starting_hand_size')->default(7);
            $table->json('deck_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_player');
    }
};
