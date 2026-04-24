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
        if (Schema::hasTable('app_updates')) {
            return;
        }

        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('update')->unique();
            $table->timestamp('ran_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_updates');
    }
};
