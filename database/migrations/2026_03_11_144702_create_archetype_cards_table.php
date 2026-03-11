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
        Schema::create('archetype_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archetype_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->boolean('sideboard')->default(false);
            $table->timestamps();

            $table->unique(['archetype_id', 'card_id', 'sideboard']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archetype_cards');
    }
};
