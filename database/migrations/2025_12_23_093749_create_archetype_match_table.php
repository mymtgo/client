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
        Schema::create('match_archetypes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Archetype::class)->constrained();
            $table->foreignIdFor(\App\Models\MtgoMatch::class)->constrained();
            $table->foreignIdFor(\App\Models\Player::class)->constrained();
            $table->decimal('confidence')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_archetypes');
    }
};
