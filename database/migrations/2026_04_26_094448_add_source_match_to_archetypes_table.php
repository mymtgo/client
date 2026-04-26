<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existing = Schema::getColumnListing('archetypes');

        Schema::table('archetypes', function (Blueprint $table) use ($existing) {
            if (! in_array('source_match_id', $existing, true)) {
                $table->foreignId('source_match_id')
                    ->nullable()
                    ->constrained('matches')
                    ->nullOnDelete();
            }
            if (! in_array('incomplete', $existing, true)) {
                $table->boolean('incomplete')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_match_id');
            $table->dropColumn('incomplete');
        });
    }
};
