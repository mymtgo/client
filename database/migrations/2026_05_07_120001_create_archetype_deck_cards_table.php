<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('archetype_deck_cards')) {
            return;
        }

        Schema::create('archetype_deck_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archetype_deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->boolean('sideboard')->default(false);
            $table->timestamps();

            $table->unique(['archetype_deck_id', 'card_id', 'sideboard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_deck_cards');
    }
};
