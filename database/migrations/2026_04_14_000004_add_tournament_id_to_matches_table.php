<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('tournament_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('tournament_round')->nullable()->after('tournament_id');
            $table->json('participant_login_ids')->nullable()->after('tournament_round');

            $table->index(['tournament_id', 'tournament_round']);
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['tournament_id', 'tournament_round']);
            $table->dropConstrainedForeignId('tournament_id');
            $table->dropColumn(['tournament_round', 'participant_login_ids']);
        });
    }
};
