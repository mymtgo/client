<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            if (! Schema::hasColumn('archetypes', 'is_fallback')) {
                $table->boolean('is_fallback')->default(false)->after('manual');
            }
        });

        Schema::table('archetypes', function (Blueprint $table) {
            $table->string('format')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            if (Schema::hasColumn('archetypes', 'is_fallback')) {
                $table->dropColumn('is_fallback');
            }
        });

        Schema::table('archetypes', function (Blueprint $table) {
            $table->string('format')->nullable(false)->change();
        });
    }
};
