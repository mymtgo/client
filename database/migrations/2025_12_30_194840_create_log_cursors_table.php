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
        Schema::create('log_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('local_username')->nullable()->index();
            $table->string('file_path')->index();
            $table->unsignedBigInteger('file_mtime')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('head_hash', 40)->index()->nullable(); // sha1
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_cursors');
    }
};
