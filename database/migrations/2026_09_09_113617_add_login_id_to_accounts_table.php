<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The MTGO account id from the login line, `Username: name (3022021)`.
     * Usernames can be renamed on MTGO; this id cannot, and it is what the
     * API keys players on. Nullable because an account is registered from
     * the username the moment it appears, sometimes before a line with the
     * id has been read.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('login_id')->nullable()->unique()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['login_id']);
            $table->dropColumn('login_id');
        });
    }
};
