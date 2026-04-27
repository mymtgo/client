<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existing = Schema::getColumnListing('game_logs');

        Schema::table('game_logs', function (Blueprint $table) use ($existing) {
            if (! in_array('decoded_entries', $existing, true)) {
                $table->json('decoded_entries')->nullable()->after('file_path');
            }
            if (! in_array('decoded_at', $existing, true)) {
                $table->dateTime('decoded_at')->nullable()->after('decoded_entries');
            }
            if (! in_array('byte_offset', $existing, true)) {
                $table->unsignedInteger('byte_offset')->default(0)->after('decoded_at');
            }
            if (! in_array('decoded_version', $existing, true)) {
                $table->unsignedSmallInteger('decoded_version')->default(0)->after('byte_offset');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_logs', function (Blueprint $table) {
            $table->dropColumn(['decoded_entries', 'decoded_at', 'byte_offset', 'decoded_version']);
        });
    }
};
