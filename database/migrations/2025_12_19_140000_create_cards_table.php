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
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('mtgo_id')->index();
            $table->string('scryfall_id')->index()->nullable();
            $table->string('oracle_id')->index()->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable()->index();
            $table->string('sub_type')->nullable()->index();
            $table->string('rarity')->nullable()->index();
            $table->string('color_identity')->nullable()->index();
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
