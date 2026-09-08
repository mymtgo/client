<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('matches', 'manual')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table): void {
            $table->boolean('manual')->default(false)->index()->after('imported');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('matches', 'manual')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table): void {
            $table->dropIndex(['manual']);
            $table->dropColumn('manual');
        });
    }
};
