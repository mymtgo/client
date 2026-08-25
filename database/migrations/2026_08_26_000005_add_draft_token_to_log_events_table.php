<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            if (! Schema::hasColumn('log_events', 'draft_token')) {
                $table->string('draft_token')->nullable()->after('tournament_token');
                $table->index(['event_type', 'processed_at', 'draft_token'], 'log_events_draft_discovery_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            if (Schema::hasColumn('log_events', 'draft_token')) {
                $table->dropIndex('log_events_draft_discovery_idx');
                $table->dropColumn('draft_token');
            }
        });
    }
};
