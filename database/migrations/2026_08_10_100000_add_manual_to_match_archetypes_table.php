<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('match_archetypes') || Schema::hasColumn('match_archetypes', 'manual')) {
            return;
        }

        Schema::table('match_archetypes', function (Blueprint $table): void {
            $table->boolean('manual')->default(false)->after('confidence');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('match_archetypes', 'manual')) {
            return;
        }

        Schema::table('match_archetypes', function (Blueprint $table): void {
            $table->dropColumn('manual');
        });
    }
};
