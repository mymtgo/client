<?php

use App\Models\Archetype;
use App\Models\MtgoMatch;
use App\Models\Player;
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
            $table->foreignIdFor(Archetype::class)->constrained();
            $table->foreignIdFor(MtgoMatch::class)->constrained();
            $table->foreignIdFor(Player::class)->constrained();
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
