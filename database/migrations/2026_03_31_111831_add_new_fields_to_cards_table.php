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
        Schema::table('cards', function (Blueprint $table) {
            $table->string('colors')->nullable()->after('color_identity');
            $table->decimal('cmc', 4, 1)->nullable()->after('colors');
            $table->string('set_name')->nullable()->after('cmc');
            $table->string('set_code')->nullable()->after('set_name');
            $table->string('art_crop')->nullable()->after('set_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['colors', 'cmc', 'set_name', 'set_code', 'art_crop']);
        });
    }
};
