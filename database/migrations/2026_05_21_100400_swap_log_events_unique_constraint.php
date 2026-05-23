<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->dropUnique('log_events_file_start_unique');
        });

        Schema::table('log_events', function (Blueprint $table) {
            $table->unique(['log_instance_id', 'byte_offset_start'], 'log_events_instance_start_unique');
        });

        Schema::table('log_events', function (Blueprint $table) {
            $table->foreignId('log_instance_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->foreignId('log_instance_id')->nullable()->change();
        });

        Schema::table('log_events', function (Blueprint $table) {
            $table->dropUnique('log_events_instance_start_unique');
        });

        Schema::table('log_events', function (Blueprint $table) {
            $table->unique(['file_path', 'byte_offset_start'], 'log_events_file_start_unique');
        });
    }
};
