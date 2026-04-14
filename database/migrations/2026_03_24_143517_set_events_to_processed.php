<?php

use App\Models\LogEvent;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        LogEvent::whereNull('processed_at')->where('created_at', '<', now()->addMinute())->update([
            'processed_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
