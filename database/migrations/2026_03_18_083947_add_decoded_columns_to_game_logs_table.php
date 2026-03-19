<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_logs', function (Blueprint $table) {
            $table->json('decoded_entries')->nullable()->after('file_path');
            $table->dateTime('decoded_at')->nullable()->after('decoded_entries');
            $table->unsignedInteger('byte_offset')->default(0)->after('decoded_at');
            $table->unsignedSmallInteger('decoded_version')->default(0)->after('byte_offset');
        });
    }

    public function down(): void
    {
        Schema::table('game_logs', function (Blueprint $table) {
            $table->dropColumn(['decoded_entries', 'decoded_at', 'byte_offset', 'decoded_version']);
        });
    }
};
