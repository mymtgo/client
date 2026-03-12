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
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dateTime('decklist_downloaded_at')->nullable()->after('color_identity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropColumn('decklist_downloaded_at');
        });
    }
};
