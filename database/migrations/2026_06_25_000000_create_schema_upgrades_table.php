<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schema_upgrades')) {
            return;
        }

        Schema::create('schema_upgrades', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->string('stage')->nullable();
            $table->integer('progress')->default(0);
            $table->integer('total')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_upgrades');
    }
};
