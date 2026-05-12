<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table): void {
            $table->foreignId('merged_into_id')
                ->nullable()
                ->after('id')
                ->constrained('archetypes')
                ->nullOnDelete();

            $table->index('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
