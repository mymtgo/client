<?php

use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limited_deck_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(League::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(MtgoMatch::class, 'match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignIdFor(Game::class)->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->json('cards');
            $table->longText('signature');
            $table->dateTime('captured_at');
            $table->timestamps();
            $table->unique(['league_id', 'match_id', 'source'], 'limited_snapshots_league_match_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limited_deck_snapshots');
    }
};
