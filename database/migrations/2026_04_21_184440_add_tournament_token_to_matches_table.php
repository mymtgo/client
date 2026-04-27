<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            if (! Schema::hasColumn('matches', 'tournament_token')) {
                $table->string('tournament_token')->nullable()->after('tournament_round')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            if (Schema::hasColumn('matches', 'tournament_token')) {
                $table->dropIndex(['tournament_token']);
                $table->dropColumn('tournament_token');
            }
        });
    }
};
