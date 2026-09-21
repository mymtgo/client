<?php

use App\Actions\Sidecar\ApplySidecarProjection;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameFieldDiff;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-apply-'.uniqid();
    mkdir($this->dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $this->dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $this->dir.'/status.json');
    AppSettings::setSidecarDirectory($this->dir);
    AppSettings::setSystemTimezone('Europe/London');
    IngestSidecarEvents::resetTick();
    IngestSidecarEvents::run();

    $this->match = MtgoMatch::factory()->create([
        'mtgo_id' => '288955358',
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'games_won' => 2,
        'games_lost' => 0,
    ]);
    $local = Player::factory()->create(['username' => 'saidin.raken']);
    $opp = Player::factory()->create(['username' => 'Opp_Name']);

    $this->game1 = Game::factory()->create(['match_id' => $this->match->id, 'mtgo_id' => '958291826', 'won' => true, 'started_at' => '2026-08-05 12:16:12', 'ended_at' => '2026-08-05 12:23:59']);
    $this->game2 = Game::factory()->create(['match_id' => $this->match->id, 'mtgo_id' => '958292028', 'won' => true, 'started_at' => '2026-08-05 12:26:01', 'ended_at' => '2026-08-05 12:34:00']);

    foreach ([$this->game1, $this->game2] as $g) {
        $g->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'on_play' => $g->is($this->game1)]);
        $g->players()->attach($opp->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => ! $g->is($this->game1)]);
    }

    GameTimeline::create(['game_id' => $this->game1->id, 'timestamp' => '13:16:41', 'content' => json_encode([
        'Players' => [['Id' => 0, 'Name' => 'saidin.raken'], ['Id' => 1, 'Name' => 'Opp_Name']],
        'Cards' => [
            ['Id' => 445, 'CatalogID' => 132587, 'Zone' => 'Battlefield', 'Owner' => 0],
            ['Id' => 446, 'CatalogID' => 39339, 'Zone' => 'Battlefield', 'Owner' => 0],
        ],
    ])]);

    // The fixture's game 2 only has a game_start keyframe (no cards), so it
    // could never be cross-checked. Add a turn keyframe with one card and a
    // matching snapshot so game 2 can become eligible when a flag is on.
    $instance = GameEvent::first()->log_instance_id;
    GameEvent::factory()->create([
        'log_instance_id' => $instance,
        'session' => 'aaaaaaaa-0000-0000-0000-000000000001',
        'session_started_at' => '2026-08-05 11:20:47.000',
        'seq' => 100,
        'ts' => '2026-08-05 12:30:00.000',
        'type' => 'keyframe',
        'game_mtgo_id' => '958292028',
        'match_mtgo_id' => '288955358',
        'verified' => true,
        'data' => ['trigger' => 'turn', 'turn' => 3, 'active_p' => 1, 'priority_p' => 1, 'players' => [['p' => 0], ['p' => 1]],
            'cards' => [['c' => '600', 'zone' => 'Battlefield', 'owner_p' => 1, 'controller_p' => 1, 'catalog_id' => 87907, 'tapped' => false]]],
    ]);
    GameTimeline::create(['game_id' => $this->game2->id, 'timestamp' => '13:30:00', 'content' => json_encode([
        'Players' => [['Id' => 0, 'Name' => 'saidin.raken'], ['Id' => 1, 'Name' => 'Opp_Name']],
        'Cards' => [['Id' => 600, 'CatalogID' => 87907, 'Zone' => 'Battlefield', 'Owner' => 1]],
    ])]);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('writes clock summaries regardless of flags', function () {
    ApplySidecarProjection::run($this->match);

    $pivot = $this->game1->players()->where('username', 'saidin.raken')->first()->pivot;
    expect($pivot->clock_remaining_ms_start)->toBe(1500000)
        ->and($pivot->clock_remaining_ms_end)->toBe(1300000)
        ->and($pivot->clock_remaining_ms_min)->toBe(1300000)
        ->and($pivot->sideboard_ms_used)->toBeNull();

    $opp = $this->game2->players()->where('username', 'Opp_Name')->first()->pivot;
    expect($opp->clock_remaining_ms_end)->toBe(1100000)->and($opp->sideboard_ms_used)->toBe(105000);
    expect($this->game2->players()->where('username', 'saidin.raken')->first()->pivot->sideboard_ms_used)->toBe(65000);
});

it('records disagreements but keeps log values when flags are off', function () {
    ApplySidecarProjection::run($this->match);

    expect($this->game2->fresh()->won)->toBeTrue()
        ->and(GameFieldDiff::where('game_id', $this->game2->id)->where('field', 'game_result')->value('chosen_source'))->toBe('log')
        ->and(GameFieldDiff::where('game_id', $this->game2->id)->where('field', 'game_result')->first()->sidecar_value)->toBe(['winner' => 'Opp_Name'])
        ->and(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_result')->exists())->toBeFalse()
        ->and(GameFieldDiff::whereNull('game_id')->where('field', 'match_result')->value('chosen_source'))->toBe('log')
        ->and($this->match->fresh()->games_won)->toBe(2);
});

it('applies sidecar game result when the flag is on and every gate passes', function () {
    SidecarAuthorityFlags::applyRemote(['game_result' => true]);

    ApplySidecarProjection::run($this->match);

    expect($this->game2->fresh()->won)->toBeFalse()
        ->and(GameFieldDiff::where('game_id', $this->game2->id)->where('field', 'game_result')->value('chosen_source'))->toBe('sidecar');
});

it('keeps the log value when the cross check fails even with the flag on', function () {
    SidecarAuthorityFlags::applyRemote(['game_boundaries' => true]);
    // Well outside BOUNDARY_TOLERANCE_SECONDS, so the two sources really do
    // disagree and the diff row below is about authority, not precision.
    $this->game1->update(['started_at' => '2026-08-05 12:17:30']);
    GameTimeline::where('game_id', $this->game1->id)->update(['content' => json_encode([
        'Players' => [['Id' => 0, 'Name' => 'saidin.raken'], ['Id' => 1, 'Name' => 'Opp_Name']],
        'Cards' => [['Id' => 445, 'CatalogID' => 132587, 'Zone' => 'Battlefield', 'Owner' => 0]],
    ])]);

    ApplySidecarProjection::run($this->match);

    expect($this->game1->fresh()->started_at->format('Y-m-d H:i:s'))->toBe('2026-08-05 12:17:30')
        ->and(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_boundaries')->value('chosen_source'))->toBe('log');
});

it('applies match result when every game is covered and the flag is on', function () {
    SidecarAuthorityFlags::applyRemote(['match_result' => true]);

    ApplySidecarProjection::run($this->match);

    $m = $this->match->fresh();
    expect($m->games_won)->toBe(1)->and($m->games_lost)->toBe(1)->and($m->outcome)->toBe(MatchOutcome::Draw)
        ->and($m->state)->toBe(MatchState::Complete);
});

it('does not promote match result when a DB game has no sidecar coverage at all', function () {
    SidecarAuthorityFlags::applyRemote(['match_result' => true]);
    GameEvent::where('game_mtgo_id', '958292028')->delete();

    ApplySidecarProjection::run($this->match);

    $m = $this->match->fresh();
    expect($m->outcome)->toBe(MatchOutcome::Win)
        ->and($m->games_won)->toBe(2)
        ->and($m->games_lost)->toBe(0)
        ->and(GameFieldDiff::whereNull('game_id')->where('field', 'match_result')->exists())->toBeFalse();
});

it('is idempotent and clears a diff once the sources agree', function () {
    ApplySidecarProjection::run($this->match);
    ApplySidecarProjection::run($this->match);
    expect(GameFieldDiff::where('game_id', $this->game2->id)->where('field', 'game_result')->count())->toBe(1);

    $this->game2->update(['won' => false]);
    ApplySidecarProjection::run($this->match);
    expect(GameFieldDiff::where('game_id', $this->game2->id)->where('field', 'game_result')->exists())->toBeFalse();
});

it('does nothing for a match with no sidecar events', function () {
    GameEvent::query()->delete();

    ApplySidecarProjection::run($this->match);

    expect(GameFieldDiff::count())->toBe(0)
        ->and($this->game1->players()->first()->pivot->clock_remaining_ms_end)->toBeNull();
});

it('touches nothing but the clock when every flag is off and the log has no value yet', function () {
    // The live shape of a game the log has not resolved: won still null,
    // no ended_at, on_play false on both pivots because CreateGames
    // defaults it that way. The sidecar has a value for all three, so a
    // resolver that treated null as "no opinion" would write all three.
    $this->game1->update(['won' => null, 'ended_at' => null]);
    foreach ($this->game1->players as $player) {
        $this->game1->players()->updateExistingPivot($player->id, ['on_play' => false]);
    }

    ApplySidecarProjection::run($this->match);

    $game = $this->game1->fresh();

    expect($game->won)->toBeNull()
        ->and($game->started_at->format('Y-m-d H:i:s'))->toBe('2026-08-05 12:16:12')
        ->and($game->ended_at)->toBeNull()
        ->and(GameFieldDiff::where('game_id', $this->game1->id)->count())->toBe(0);

    foreach ($game->players as $player) {
        expect((bool) $player->pivot->on_play)->toBeFalse();
    }

    // Clock is sidecar-only, so it is still written.
    expect($game->players->firstWhere('username', 'saidin.raken')->pivot->clock_remaining_ms_start)->toBe(1500000);
});

it('treats a boundary within the tolerance as agreement', function () {
    // Sidecar game 1 runs 12:16:10.000 to 12:24:00.500.
    $this->game1->update(['started_at' => '2026-08-05 12:16:13', 'ended_at' => '2026-08-05 12:24:00']);

    ApplySidecarProjection::run($this->match);

    expect(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_boundaries')->exists())->toBeFalse();
});

it('records a boundary disagreement once a bound drifts past the tolerance', function () {
    $this->game1->update(['started_at' => '2026-08-05 12:16:40', 'ended_at' => '2026-08-05 12:24:00']);

    ApplySidecarProjection::run($this->match);

    expect(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_boundaries')->value('chosen_source'))->toBe('log');
});

it('clears a boundary diff once the sources come back within tolerance', function () {
    $this->game1->update(['started_at' => '2026-08-05 12:16:40', 'ended_at' => '2026-08-05 12:24:00']);
    ApplySidecarProjection::run($this->match);
    expect(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_boundaries')->exists())->toBeTrue();

    $this->game1->update(['started_at' => '2026-08-05 12:16:13']);
    ApplySidecarProjection::run($this->match);

    expect(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_boundaries')->exists())->toBeFalse();
});

it('does not record a boundary diff while the game is still running', function () {
    // No log ended_at yet: comparing a half-open range against the
    // sidecar's completed pair would write a diff row this tick and delete
    // it the next, for every tick of every match.
    $this->game1->update(['started_at' => '2026-08-05 12:17:30', 'ended_at' => null]);

    ApplySidecarProjection::run($this->match);

    expect(GameFieldDiff::where('game_id', $this->game1->id)->where('field', 'game_boundaries')->exists())->toBeFalse();
});
