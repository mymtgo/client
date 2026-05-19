<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('card_stat_ship_queue')) {
            return;
        }

        Schema::create('card_stat_ship_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Game::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(MtgoMatch::class, 'match_id')->constrained('matches')->cascadeOnDelete();
            $table->json('payload');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_stat_ship_queue');
    }
};
