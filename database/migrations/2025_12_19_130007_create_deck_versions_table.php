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
        Schema::create('deck_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Deck::class)->constrained();
            $table->longText('signature')->index();
            $table->dateTime('modified_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deck_versions');
    }
};
