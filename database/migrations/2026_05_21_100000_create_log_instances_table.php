<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('log_instances')) {
            return;
        }

        Schema::create('log_instances', function (Blueprint $table) {
            $table->id();
            $table->string('file_path', 1024);
            $table->string('identity_hash', 40)->index();
            $table->unsignedBigInteger('file_ctime')->nullable();
            $table->string('head_hash', 40);
            $table->unsignedBigInteger('anchor_offset')->nullable();
            $table->string('anchor_hash', 40)->nullable();
            $table->string('tail_hash', 40)->nullable();
            $table->string('local_username')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('sealed_at')->nullable();
            $table->string('seal_reason')->nullable();
            $table->timestamps();

            $table->index(['file_path', 'sealed_at'], 'log_instances_path_sealed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_instances');
    }
};
