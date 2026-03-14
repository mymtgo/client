<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->string('username', 20)->nullable()->after('logged_at')->index();
        });

        // Backfill: find the login event username and stamp all existing rows
        $loginEvent = DB::table('log_events')
            ->where('category', 'Login')
            ->where('context', 'MtGO Login Success')
            ->first();

        if ($loginEvent) {
            $username = $this->extractLoginUsername($loginEvent->raw_text);

            if ($username) {
                DB::table('log_events')->update(['username' => $username]);
            }
        }
    }

    private function extractLoginUsername(string $raw): ?string
    {
        if (preg_match('/Username:\s*(\S+)/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }
};
