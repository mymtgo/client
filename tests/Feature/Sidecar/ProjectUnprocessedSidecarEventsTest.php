<?php

use App\Actions\Sidecar\IngestSidecarEvents;
use App\Actions\Sidecar\ProjectUnprocessedSidecarEvents;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-sweep-'.uniqid();
    mkdir($this->dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $this->dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $this->dir.'/status.json');
    AppSettings::setSidecarDirectory($this->dir);
    IngestSidecarEvents::resetTick();
    IngestSidecarEvents::run();
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

function sweepMatchWithGames(): MtgoMatch
{
    $match = MtgoMatch::factory()->create(['mtgo_id' => '288955358', 'state' => MatchState::Complete]);
    $local = Player::factory()->create(['username' => 'saidin.raken']);
    $opponent = Player::factory()->create(['username' => 'Opp_Name']);

    foreach (['958291826', '958292028'] as $gameMtgoId) {
        $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => $gameMtgoId]);
        $game->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'on_play' => false]);
        $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false]);
    }

    return $match;
}

it('leaves ingested events unprocessed until something projects them', function () {
    expect(GameEvent::whereNull('processed_at')->count())->toBe(30);
});

it('projects a match no log event would have revisited and marks its events processed', function () {
    $match = sweepMatchWithGames();

    expect(ProjectUnprocessedSidecarEvents::run())->toBe(1);

    $pivot = Game::where('mtgo_id', '958291826')->first()
        ->players()->where('username', 'saidin.raken')->first()->pivot;

    expect($pivot->clock_remaining_ms_start)->toBe(1500000)
        ->and($pivot->clock_remaining_ms_end)->toBe(1300000)
        ->and(GameEvent::where('match_mtgo_id', $match->mtgo_id)->whereNull('processed_at')->count())->toBe(0);
});

it('does no work on a second sweep', function () {
    sweepMatchWithGames();

    ProjectUnprocessedSidecarEvents::run();

    expect(ProjectUnprocessedSidecarEvents::run())->toBe(0);
});

it('leaves events whose match has no row yet unprocessed so a later tick retries them', function () {
    GameEvent::factory()->create([
        'match_mtgo_id' => '777777777',
        'game_mtgo_id' => '888888888',
        'verified' => true,
    ]);

    sweepMatchWithGames();

    expect(ProjectUnprocessedSidecarEvents::run())->toBe(1)
        ->and(GameEvent::where('match_mtgo_id', '777777777')->whereNull('processed_at')->count())->toBe(1);

    // And it is picked up the moment the log pipeline creates the match.
    MtgoMatch::factory()->create(['mtgo_id' => '777777777', 'state' => MatchState::Complete]);

    expect(ProjectUnprocessedSidecarEvents::run())->toBe(1)
        ->and(GameEvent::where('match_mtgo_id', '777777777')->whereNull('processed_at')->count())->toBe(0);
});

it('marks a match whose only unprocessed events are replay detail', function () {
    // The fold reads none of these types, so the projection returns early
    // without marking anything. Left unmarked they would be re-swept on
    // every tick forever.
    $match = MtgoMatch::factory()->create(['mtgo_id' => '999888777', 'state' => MatchState::Complete]);

    GameEvent::factory()->create([
        'match_mtgo_id' => $match->mtgo_id,
        'game_mtgo_id' => '111222333',
        'type' => 'card_tapped',
    ]);

    expect(ProjectUnprocessedSidecarEvents::run())->toBeGreaterThan(0)
        ->and(GameEvent::where('match_mtgo_id', $match->mtgo_id)->whereNull('processed_at')->count())->toBe(0);
});
