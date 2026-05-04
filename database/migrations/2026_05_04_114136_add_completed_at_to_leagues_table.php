<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leagues', 'completed_at')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            $table->dateTime('completed_at')->nullable()->after('dropped_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leagues', 'completed_at')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
