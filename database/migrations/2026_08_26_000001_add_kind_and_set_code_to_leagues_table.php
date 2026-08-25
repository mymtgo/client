<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            if (! Schema::hasColumn('leagues', 'kind')) {
                $table->string('kind', 16)->default('constructed')->index();
            }
            if (! Schema::hasColumn('leagues', 'set_code')) {
                $table->string('set_code', 8)->nullable()->index();
            }
            if (! Schema::hasColumn('leagues', 'mtgo_course_id')) {
                $table->unsignedInteger('mtgo_course_id')->nullable();
                $table->unique(['event_id', 'mtgo_course_id'], 'leagues_event_course_unique');
            }
        });
    }

    public function down(): void
    {
        /**
         * Mirror of up()'s guards. up() only adds each column and index when
         * it is missing, so nothing here is guaranteed to be present on the
         * way back down. SQLite also refuses to drop a column that still
         * has an index on it, so every index goes first.
         */
        Schema::table('leagues', function (Blueprint $table) {
            foreach ([
                'leagues_event_course_unique',
                'leagues_kind_index',
                'leagues_set_code_index',
            ] as $index) {
                if (Schema::hasIndex('leagues', $index)) {
                    $table->dropIndex($index);
                }
            }

            $columns = array_values(array_filter(
                ['kind', 'set_code', 'mtgo_course_id'],
                fn (string $column): bool => Schema::hasColumn('leagues', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
