<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * No-op. Originally re-synced game results via GetGameLog/SyncGameResults,
     * both removed in the pipeline cleanup (commit b0c6be5). Kept as a no-op
     * so existing installs whose migration table never recorded this entry
     * can advance past it; subsequent installs would have no data to repair.
     */
    public function up(): void {}

    public function down(): void {}
};
