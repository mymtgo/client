<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * No-op. Originally re-evaluated match results via GetGameLog/SyncGameResults/
     * DetermineMatchResult, all removed in the pipeline cleanup (commit b0c6be5).
     * Kept as a no-op so installs that crashed here can advance past it.
     */
    public function up(): void {}

    public function down(): void {}
};
