<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            if (! Schema::hasColumn('matches', 'tournament_id')) {
                $table->foreignId('tournament_id')
                    ->nullable()
                    ->after('league_id')
                    ->constrained('tournaments')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            if (Schema::hasColumn('matches', 'tournament_id')) {
                $table->dropConstrainedForeignId('tournament_id');
            }
        });
    }
};
