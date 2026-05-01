<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leagues', 'dropped_at')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            $table->dateTime('dropped_at')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leagues', 'dropped_at')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn('dropped_at');
        });
    }
};
