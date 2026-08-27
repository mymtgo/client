<?php

use App\Models\Draft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Draft::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->unsignedTinyInteger('pack_number');
            $table->unsignedTinyInteger('pick_number');
            $table->unsignedBigInteger('pack_id')->nullable()->index();
            $table->unsignedTinyInteger('direction')->nullable();
            $table->json('cards_available');
            $table->unsignedInteger('picked_catalog_id')->nullable()->index();
            $table->unsignedBigInteger('picked_card_id')->nullable();
            $table->json('reservations');
            $table->dateTime('shown_at')->nullable();
            $table->dateTime('deadline_at')->nullable();
            $table->dateTime('picked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['draft_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_picks');
    }
};
