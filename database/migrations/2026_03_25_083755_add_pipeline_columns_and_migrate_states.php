<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('outcome');
            $table->unsignedTinyInteger('attempts')->default(0)->after('failed_at');
        });

        // Migrate existing state data
        DB::table('matches')->where('state', 'pending_result')->update(['state' => 'ended']);

        // Delete voided matches — disable FK checks to handle child records
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('matches')->where('state', 'voided')->delete();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['failed_at', 'attempts']);
        });
    }
};
