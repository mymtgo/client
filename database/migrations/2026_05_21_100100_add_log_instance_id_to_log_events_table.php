<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency: a prior interrupted run may have added the column
        // before the migrations row was recorded. Re-running would throw
        // "duplicate column name" and abort the whole migration batch,
        // stranding the user on a half-migrated schema.
        if (Schema::hasColumn('log_events', 'log_instance_id')) {
            return;
        }

        Schema::table('log_events', function (Blueprint $table) {
            $table->foreignId('log_instance_id')
                ->nullable()
                ->after('id')
                ->constrained('log_instances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('log_events', 'log_instance_id')) {
            return;
        }

        Schema::table('log_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('log_instance_id');
        });
    }
};
