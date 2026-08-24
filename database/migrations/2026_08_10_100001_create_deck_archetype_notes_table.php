<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deck_archetype_notes')) {
            return;
        }

        Schema::create('deck_archetype_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deck_id')->constrained('decks')->cascadeOnDelete();
            $table->foreignId('archetype_id')->constrained('archetypes')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['deck_id', 'archetype_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_archetype_notes');
    }
};
