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
        $existing = Schema::getColumnListing('cards');

        Schema::table('cards', function (Blueprint $table) use ($existing) {
            if (! in_array('colors', $existing, true)) {
                $table->string('colors')->nullable()->after('color_identity');
            }
            if (! in_array('cmc', $existing, true)) {
                $table->decimal('cmc', 4, 1)->nullable()->after('colors');
            }
            if (! in_array('set_name', $existing, true)) {
                $table->string('set_name')->nullable()->after('cmc');
            }
            if (! in_array('set_code', $existing, true)) {
                $table->string('set_code')->nullable()->after('set_name');
            }
            if (! in_array('art_crop', $existing, true)) {
                $table->string('art_crop')->nullable()->after('set_code');
            }
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
