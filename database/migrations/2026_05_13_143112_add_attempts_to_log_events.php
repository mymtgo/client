<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0)->after('processed_at');
            $table->timestamp('abandoned_at')->nullable()->after('attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table): void {
            $table->dropColumn(['attempts', 'abandoned_at']);
        });
    }
};
