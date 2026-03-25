<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->boolean('is_postboard')->default(false)->after('won');
            $table->boolean('sided_out')->default(false)->after('is_postboard');
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn(['is_postboard', 'sided_out']);
        });
    }
};
