<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('archetype_decks')) {
            return;
        }

        Schema::create('archetype_decks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('archetype_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seen_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['archetype_id', 'seen_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_decks');
    }
};
