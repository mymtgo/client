<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'current')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->boolean('current')->default(false)->after('active');
            });
        }

        // Backfill: mark the account matching the log cursor username as current
        $username = DB::table('log_cursors')->value('local_username');

        if ($username) {
            DB::table('accounts')
                ->where('username', $username)
                ->update(['current' => true]);
        }
    }
};
