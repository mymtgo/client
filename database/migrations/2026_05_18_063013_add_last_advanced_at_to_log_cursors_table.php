<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_cursors', function (Blueprint $table) {
            if (! Schema::hasColumn('log_cursors', 'last_advanced_at')) {
                $table->timestamp('last_advanced_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('log_cursors', function (Blueprint $table) {
            if (Schema::hasColumn('log_cursors', 'last_advanced_at')) {
                $table->dropIndex(['last_advanced_at']);
                $table->dropColumn('last_advanced_at');
            }
        });
    }
};
