<?php

use App\Enums\MatchState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Void any soft-deleted matches before dropping the column
        DB::table('matches')
            ->whereNotNull('deleted_at')
            ->update(['state' => MatchState::Voided->value]);

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
