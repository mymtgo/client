<?php

use App\Facades\AppSettings;
use App\Jobs\RunSchemaUpgradeJob;
use App\Models\Account;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\SchemaUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helper — reuses the legacy-match builder pattern from RunParticipantBackfillTest
// ---------------------------------------------------------------------------

/**
 * Insert a legacy `game_player` row.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertFlowGamePlayer(int $gameId, int $playerId, array $overrides = []): void
{
    DB::table('game_player')->insert(array_merge([
        'game_id' => $gameId,
        'player_id' => $playerId,
        'instance_id' => 100,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'dice_roll' => null,
        'mulligan_count' => null,
        'deck_json' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Build a legacy-schema match with game_player rows so the backfill has data to convert.
 */
function buildFlowLegacyMatch(int $localId, int $opponentId): MtgoMatch
{
    $match = MtgoMatch::factory()->create([
        'account_id' => null,
        'opponent_id' => null,
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => null,
        'local_mulligans' => null,
        'opp_mulligans' => null,
        'local_dice' => null,
        'opp_dice' => null,
        'local_instance' => null,
        'opp_instance' => null,
    ]);

    insertFlowGamePlayer($game->id, $localId, [
        'is_local' => true,
        'on_play' => true,
        'instance_id' => 10,
        'dice_roll' => 12,
        'mulligan_count' => 0,
        'deck_json' => json_encode([['mtgo_id' => 101, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    insertFlowGamePlayer($game->id, $opponentId, [
        'is_local' => false,
        'on_play' => false,
        'instance_id' => 20,
        'dice_roll' => 5,
        'mulligan_count' => 1,
        'deck_json' => json_encode([['mtgo_id' => 202, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    return $match;
}

// ---------------------------------------------------------------------------
// ShowController
// ---------------------------------------------------------------------------

it('renders the upgrade show page via Inertia', function () {
    $this->get(route('upgrade.show'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('upgrade/Show'));
});

it('passes a pending upgrade to the show page when one exists', function () {
    $upgrade = SchemaUpgrade::create(['status' => 'pending']);

    $this->get(route('upgrade.show'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('upgrade/Show')
            ->has('pendingUpgrade')
            ->where('pendingUpgrade.id', $upgrade->id)
        );
});

// ---------------------------------------------------------------------------
// StartController → Job → StatusController (end-to-end wiring)
// ---------------------------------------------------------------------------

it('POST upgrade.start creates a SchemaUpgrade and returns its id', function () {
    $response = $this->postJson(route('upgrade.start'));

    $response->assertSuccessful()
        ->assertJsonStructure(['upgrade_id']);

    expect(SchemaUpgrade::count())->toBe(1);
});

it('runs the backfill job synchronously (sync queue) and status endpoint reports complete', function () {
    // Seed one legacy match so the backfill has real work to do
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);
    buildFlowLegacyMatch($localId, $opponentId);

    $response = $this->postJson(route('upgrade.start'));
    $response->assertSuccessful();

    $upgradeId = $response->json('upgrade_id');

    // Because the test queue driver is sync, the job has already run
    $this->get(route('upgrade.status', $upgradeId))
        ->assertSuccessful()
        ->assertJsonFragment(['status' => 'complete']);
});

it('populates account_id and opponent_id on the seeded match after the upgrade completes', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);
    $match = buildFlowLegacyMatch($localId, $opponentId);

    $this->postJson(route('upgrade.start'))->assertSuccessful();

    $match->refresh();
    expect($match->account_id)->not->toBeNull();
    expect($match->opponent_id)->not->toBeNull();
});

it('does not bump data_schema_version and marks the tracker failed when the backfill throws', function () {
    // Reset to 0 so the upgrade gate engages (beforeEach sets it to TARGET).
    AppSettings::setDataSchemaVersion(0);

    // Arrange: a match with game_player rows but NO Account records.
    // BackfillMatchParticipants resolves account_id via Account::where(username) ?? Account::currentId().
    // With no accounts in the database both return null, so account_id stays NULL after stage 1.
    // DropLegacyParticipantSchema::run() then finds 1 unmigrated match and throws a RuntimeException,
    // which RunParticipantBackfill catches, marks the tracker failed, and rethrows.
    // The job's handle() never reaches AppSettings::setDataSchemaVersion(), so version stays 0.
    $localId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);
    buildFlowLegacyMatch($localId, $opponentId);

    // Create the tracker and dispatch the job directly to avoid HTTP 500 from
    // the sync queue rethrowing through the controller layer. The job's failed()
    // handler marks the tracker; the rethrown exception is caught here instead.
    $tracker = SchemaUpgrade::create(['status' => 'pending']);

    try {
        (new RunSchemaUpgradeJob($tracker->id))->handle();
    } catch (Throwable) {
        // Expected — the guard in DropLegacyParticipantSchema throws because
        // account_id was never populated (no Account records exist).
    }

    // The schema version must NOT have been bumped.
    expect(AppSettings::dataSchemaVersion())->toBe(0);

    // The tracker must be marked failed by RunParticipantBackfill's catch block.
    $tracker->refresh();
    expect($tracker->status)->toBe('failed');
});

// ---------------------------------------------------------------------------
// StatusController
// ---------------------------------------------------------------------------

it('POST upgrade.start returns the existing id and does not create a duplicate when an upgrade is already running', function () {
    $existing = SchemaUpgrade::create(['status' => 'running']);

    $response = $this->postJson(route('upgrade.start'));

    $response->assertSuccessful()
        ->assertJson(['upgrade_id' => $existing->id]);

    expect(SchemaUpgrade::count())->toBe(1);
});

it('status endpoint returns the expected JSON shape', function () {
    $upgrade = SchemaUpgrade::create(['status' => 'pending']);

    $this->get(route('upgrade.status', $upgrade->id))
        ->assertSuccessful()
        ->assertJsonStructure(['status', 'stage', 'progress', 'total', 'error']);
});
