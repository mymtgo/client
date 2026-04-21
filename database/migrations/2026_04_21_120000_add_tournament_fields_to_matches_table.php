<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedInteger('tournament_event_id')->nullable()->after('id');
            $table->unsignedSmallInteger('tournament_round')->nullable()->after('tournament_event_id');
            $table->index('tournament_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['tournament_event_id']);
            $table->dropColumn(['tournament_event_id', 'tournament_round']);
        });
    }
};
