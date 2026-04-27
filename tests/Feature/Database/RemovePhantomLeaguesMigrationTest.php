<?php

use App\Facades\AppSettings;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Simulate an install that predates the new migration by reintroducing
    // the column + data, then running the new migration by path.
    if (! Schema::hasColumn('leagues', 'phantom')) {
        Schema::table('leagues', function ($table) {
            $table->boolean('phantom')->default(false)->nullable()->index();
        });
    }
});

it('deletes phantom leagues, nulls their match league_id, drops the column, and removes the setting', function () {
    $phantom = League::factory()->create(['phantom' => true, 'name' => 'Phantom']);
    $real = League::factory()->create(['phantom' => false, 'name' => 'Real']);

    $phantomMatch = MtgoMatch::factory()->create([
        'league_id' => $phantom->id,
        'token' => 'match-phantom',
    ]);
    $realMatch = MtgoMatch::factory()->create([
        'league_id' => $real->id,
        'token' => 'match-real',
    ]);

    AppSettings::set('hide_phantom_leagues', true);

    // Run the migration being tested by path. migrate:refresh above already
    // ran it, so seed+remove it, then re-run to exercise the data path.
    DB::table('migrations')->where('migration', 'like', '%remove_phantom_leagues%')->delete();
    Schema::table('leagues', function ($table) {
        if (! Schema::hasColumn('leagues', 'phantom')) {
            $table->boolean('phantom')->default(false)->nullable()->index();
        }
    });
    DB::table('leagues')->where('id', $phantom->id)->update(['phantom' => true]);

    $this->artisan('migrate', [
        '--path' => 'database/migrations/2026_04_24_000000_remove_phantom_leagues.php',
        '--realpath' => false,
    ])->assertExitCode(0);

    expect(League::find($phantom->id))->toBeNull();
    expect(League::find($real->id))->not->toBeNull();

    expect(MtgoMatch::find($phantomMatch->id)->league_id)->toBeNull();
    expect(MtgoMatch::find($realMatch->id)->league_id)->toBe($real->id);

    expect(Schema::hasColumn('leagues', 'phantom'))->toBeFalse();

    expect(AppSettings::get('hide_phantom_leagues'))->toBeNull();
});
