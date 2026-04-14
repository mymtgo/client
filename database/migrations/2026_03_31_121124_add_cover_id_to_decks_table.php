<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->foreignId('cover_id')
                ->nullable()
                ->after('color_identity')
                ->constrained('cards')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_id');
        });
    }
};
