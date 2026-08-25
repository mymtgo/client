<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

function loadLeagueKindMigration(): object
{
    return require database_path('migrations/2026_08_26_000001_add_kind_and_set_code_to_leagues_table.php');
}

/**
 * up() adds each column and the unique index only when it is missing, so
 * down() must not assume any of them is there. Rolling back twice is the
 * cheapest way to prove the guards: without them the second pass aborts on
 * "no such index".
 */
it('rolls back twice without throwing', function () {
    $migration = loadLeagueKindMigration();

    $migration->down();

    expect(Schema::hasColumn('leagues', 'kind'))->toBeFalse()
        ->and(Schema::hasColumn('leagues', 'set_code'))->toBeFalse()
        ->and(Schema::hasColumn('leagues', 'mtgo_course_id'))->toBeFalse()
        ->and(Schema::hasIndex('leagues', 'leagues_event_course_unique'))->toBeFalse();

    $migration->down();

    expect(Schema::hasColumn('leagues', 'kind'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('leagues', 'kind'))->toBeTrue()
        ->and(Schema::hasColumn('leagues', 'set_code'))->toBeTrue()
        ->and(Schema::hasColumn('leagues', 'mtgo_course_id'))->toBeTrue()
        ->and(Schema::hasIndex('leagues', 'leagues_event_course_unique'))->toBeTrue();
});
