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
            if (! in_array('local_image', $existing, true)) {
                $table->string('local_image')->nullable()->after('image');
            }
            if (! in_array('local_art_crop', $existing, true)) {
                $table->string('local_art_crop')->nullable()->after('art_crop');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['local_image', 'local_art_crop']);
        });
    }
};
