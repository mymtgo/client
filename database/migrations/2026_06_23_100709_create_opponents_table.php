<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opponents')) {
            return;
        }

        Schema::create('opponents', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->timestamps();

            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opponents');
    }
};
