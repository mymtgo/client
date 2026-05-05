<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('mtgo_event_id')->index();
            $table->string('token')->nullable()->index();
            $table->string('name');
            $table->string('format')->nullable()->index();
            $table->dateTime('started_at');
            $table->foreignId('deck_version_id')
                ->nullable()
                ->constrained('deck_versions')
                ->nullOnDelete();
            $table->boolean('name_synthesized')->default(false);
            $table->timestamps();

            $table->unique(['mtgo_event_id', 'deck_version_id'], 'tournaments_event_deck_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
