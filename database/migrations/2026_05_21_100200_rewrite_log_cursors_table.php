<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('log_cursors')) {
            Schema::create('log_cursors_legacy_snapshot', function (Blueprint $table) {
                $table->id();
                $table->string('file_path', 1024);
                $table->unsignedBigInteger('byte_offset')->default(0);
                $table->unsignedBigInteger('file_mtime')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('head_hash', 40)->nullable();
                $table->string('local_username')->nullable();
                $table->timestamps();
            });

            DB::table('log_cursors_legacy_snapshot')->insertUsing(
                ['id', 'file_path', 'byte_offset', 'file_mtime', 'file_size', 'head_hash', 'local_username', 'created_at', 'updated_at'],
                DB::table('log_cursors')->select(
                    'id', 'file_path', 'byte_offset', 'file_mtime', 'file_size', 'head_hash', 'local_username', 'created_at', 'updated_at'
                )
            );

            Schema::drop('log_cursors');
        }

        Schema::create('log_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('log_instance_id')
                ->unique()
                ->constrained('log_instances')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->unsignedBigInteger('last_observed_size')->default(0);
            $table->timestamp('last_advance_at')->nullable();
            $table->unsignedInteger('stuck_ticks')->default(0);
            $table->unsignedBigInteger('verify_anchor_offset')->nullable();
            $table->string('verify_anchor_hash', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_cursors');

        if (Schema::hasTable('log_cursors_legacy_snapshot')) {
            Schema::create('log_cursors', function (Blueprint $table) {
                $table->id();
                $table->string('local_username')->nullable()->index();
                $table->string('file_path')->index();
                $table->unsignedBigInteger('file_mtime')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('head_hash', 40)->index()->nullable();
                $table->unsignedBigInteger('byte_offset')->default(0);
                $table->timestamps();
            });

            DB::table('log_cursors')->insertUsing(
                ['id', 'file_path', 'byte_offset', 'file_mtime', 'file_size', 'head_hash', 'local_username', 'created_at', 'updated_at'],
                DB::table('log_cursors_legacy_snapshot')->select(
                    'id', 'file_path', 'byte_offset', 'file_mtime', 'file_size', 'head_hash', 'local_username', 'created_at', 'updated_at'
                )
            );

            Schema::drop('log_cursors_legacy_snapshot');
        }
    }
};
