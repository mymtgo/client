<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leagues', 'manual')) {
            return;
        }

        Schema::table('leagues', function (Blueprint $table) {
            $table->boolean('manual')->default(false)->index()->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropIndex(['manual']);
            $table->dropColumn('manual');
        });
    }
};
