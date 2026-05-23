<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::table('log_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('log_instance_id');
        });
    }
};
