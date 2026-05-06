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
            $table->unsignedInteger('mtgo_event_id')->unique('tournaments_event_unique');
            $table->string('token')->nullable()->index();
            $table->string('name');
            $table->string('format')->nullable()->index();
            $table->dateTime('started_at');
            $table->boolean('name_synthesized')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
