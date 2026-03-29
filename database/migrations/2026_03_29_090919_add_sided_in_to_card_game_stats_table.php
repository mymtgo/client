<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->boolean('sided_in')->default(false)->after('sided_out');
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn('sided_in');
        });
    }
};
