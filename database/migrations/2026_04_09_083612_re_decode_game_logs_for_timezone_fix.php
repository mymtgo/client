<?php

use App\Jobs\ReDecodeGameLogsJob;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ReDecodeGameLogsJob::dispatch();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Timestamp correction is not reversible without the original timezone
    }
};
