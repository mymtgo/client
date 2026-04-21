<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->string('tournament_token')->nullable()->after('match_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->dropIndex(['tournament_token']);
            $table->dropColumn('tournament_token');
        });
    }
};
