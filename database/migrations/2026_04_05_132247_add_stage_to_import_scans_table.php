<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_scans', function (Blueprint $table) {
            $table->string('stage')->default('discovering')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('import_scans', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
