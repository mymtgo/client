<?php

use App\Models\League;
use App\Models\Tournament;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(League::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Tournament::class)->nullable()->constrained()->nullOnDelete();
            $table->uuid('draft_token')->unique();
            $table->unsignedInteger('mtgo_draft_id')->nullable();
            $table->uuid('pod_token')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(8);
            $table->unsignedTinyInteger('seat_index')->nullable();
            $table->unsignedInteger('booster_catalog_id')->nullable();
            $table->string('state', 16)->default('connecting')->index();
            $table->unsignedTinyInteger('pack_size')->default(14);
            $table->unsignedSmallInteger('picks_expected')->default(42);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
