<?php

use App\Actions\Leagues\FormatLeagueRuns;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $deck = Deck::factory()->create();
    $this->dv = DeckVersion::factory()->for($deck)->create();
});

function makeLeagueWithOutcomes(DeckVersion $dv, LeagueState $state, array $outcomes): League
{
    $league = League::factory()->for($dv)->create([
        'state' => $state,
        'format' => 'Modern',
    ]);

    foreach ($outcomes as $i => $outcome) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $dv->id,
            'state' => MatchState::Complete,
            'outcome' => $outcome,
            'format' => 'Modern',
            'started_at' => now()->subMinutes(60 - $i * 10),
            'ended_at' => now()->subMinutes(50 - $i * 10),
        ]);
    }

    return $league;
}

it('classifies 5-0 complete as TROPHY', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Complete, [
        MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['classification'])->toBe('TROPHY')
        ->and($runs[0]['liveRound'])->toBeNull();
});

it('classifies 4-1 complete as CASH', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Complete, [
        MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Loss,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['classification'])->toBe('CASH');
});

it('classifies 3-2 complete as FINISH', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Complete, [
        MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Loss, MatchOutcome::Loss,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['classification'])->toBe('FINISH');
});

it('classifies dropped as BRICK', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Dropped, [
        MatchOutcome::Loss, MatchOutcome::Win, MatchOutcome::Loss, MatchOutcome::Loss,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['classification'])->toBe('BRICK');
});

it('classifies active as LIVE with liveRound = matches+1', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Active, [
        MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Loss,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['classification'])->toBe('LIVE')
        ->and($runs[0]['liveRound'])->toBe(4);
});

it('computes average match duration in seconds', function () {
    $league = League::factory()->for($this->dv)->create([
        'state' => LeagueState::Complete,
        'format' => 'Modern',
    ]);

    MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'deck_version_id' => $this->dv->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'format' => 'Modern',
        'started_at' => '2026-04-01 10:00:00',
        'ended_at' => '2026-04-01 10:10:00',
    ]);

    MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'deck_version_id' => $this->dv->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
        'format' => 'Modern',
        'started_at' => '2026-04-01 11:00:00',
        'ended_at' => '2026-04-01 11:20:00',
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['avgMatchSeconds'])->toBe(900);
});

it('returns null avgMatchSeconds when no ended_at', function () {
    $league = League::factory()->for($this->dv)->create(['state' => LeagueState::Active, 'format' => 'Modern']);

    MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'deck_version_id' => $this->dv->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'format' => 'Modern',
        'started_at' => now(),
        'ended_at' => null,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['avgMatchSeconds'])->toBeNull();
});

it('returns time-of-day mode across matches', function () {
    $league = League::factory()->for($this->dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);

    foreach (['18:00:00', '19:30:00', '14:00:00'] as $time) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $this->dv->id,
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'format' => 'Modern',
            'started_at' => '2026-04-01 '.$time,
            'ended_at' => '2026-04-01 '.$time,
        ]);
    }

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['timeOfDay'])->toBe('evening');
});

it('aggregates per-game wins/losses and on-play/draw record', function () {
    $league = League::factory()->for($this->dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);

    $match = MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'deck_version_id' => $this->dv->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'format' => 'Modern',
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $local = Player::factory()->create();
    $opp = Player::factory()->create();

    $games = [
        ['won' => 1, 'on_play' => 1],
        ['won' => 0, 'on_play' => 0],
        ['won' => 1, 'on_play' => 1],
    ];

    foreach ($games as $i => $g) {
        $game = Game::factory()->create([
            'match_id' => $match->id,
            'won' => $g['won'],
            'started_at' => now()->addSeconds($i),
        ]);

        DB::table('game_player')->insert([
            'game_id' => $game->id,
            'player_id' => $local->id,
            'is_local' => true,
            'on_play' => $g['on_play'],
            'instance_id' => 1,
        ]);

        DB::table('game_player')->insert([
            'game_id' => $game->id,
            'player_id' => $opp->id,
            'is_local' => false,
            'on_play' => 1 - $g['on_play'],
            'instance_id' => 0,
        ]);
    }

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['gameWins'])->toBe(2)
        ->and($runs[0]['gameLosses'])->toBe(1)
        ->and($runs[0]['onPlayRecord'])->toBe(['wins' => 2, 'losses' => 0])
        ->and($runs[0]['onDrawRecord'])->toBe(['wins' => 0, 'losses' => 1]);
});

it('computes top opponent archetype and top matchups list', function () {
    $league = League::factory()->for($this->dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);

    $matches = collect();
    foreach (range(0, 3) as $i) {
        $matches->push(MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $this->dv->id,
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'format' => 'Modern',
            'started_at' => now()->subMinutes(60 - $i * 10),
            'ended_at' => now()->subMinutes(50 - $i * 10),
        ]));
    }

    $yawg = Archetype::factory()->create(['name' => 'Yawgmoth']);
    $burn = Archetype::factory()->create(['name' => 'Burn']);
    $hammer = Archetype::factory()->create(['name' => 'Hammer Time']);

    $players = Player::factory()->count(4)->create();

    $assignments = [
        [$matches[0], $yawg, MatchOutcome::Win],
        [$matches[1], $yawg, MatchOutcome::Loss],
        [$matches[2], $burn, MatchOutcome::Win],
        [$matches[3], $hammer, MatchOutcome::Loss],
    ];

    foreach ($assignments as $i => [$match, $arch, $outcome]) {
        $match->update(['outcome' => $outcome]);

        DB::table('match_archetypes')->insert([
            'mtgo_match_id' => $match->id,
            'archetype_id' => $arch->id,
            'player_id' => $players[$i]->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $game = Game::factory()->create([
            'match_id' => $match->id,
            'won' => $outcome === MatchOutcome::Win ? 1 : 0,
            'started_at' => now(),
        ]);

        DB::table('game_player')->insert([
            'game_id' => $game->id,
            'player_id' => $players[$i]->id,
            'is_local' => false,
            'instance_id' => 0,
        ]);
    }

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['topOpponentArchetype'])->toBe('Yawgmoth')
        ->and($runs[0]['topMatchups'])->toHaveCount(3)
        ->and($runs[0]['topMatchups'][0]['archetype'])->toBe('Yawgmoth')
        ->and($runs[0]['topMatchups'][0]['wins'])->toBe(1)
        ->and($runs[0]['topMatchups'][0]['losses'])->toBe(1);
});

it('computes tixDelta from EV table for completed runs', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Complete, [
        MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Win, MatchOutcome::Loss,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['tixDelta'])->toBe(12.70);
});

it('returns null tixDelta for active LIVE runs', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Active, [
        MatchOutcome::Win, MatchOutcome::Win,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['tixDelta'])->toBeNull();
});

it('uses padded score at drop point for BRICK runs', function () {
    $league = makeLeagueWithOutcomes($this->dv, LeagueState::Dropped, [
        MatchOutcome::Loss, MatchOutcome::Win, MatchOutcome::Loss, MatchOutcome::Loss,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    // 1 win, 3 losses → padded to 1-4 → -10.00
    expect($runs[0]['tixDelta'])->toBe(-10.00);
});

it('exposes per-match durationSeconds', function () {
    $league = League::factory()->for($this->dv)->create(['state' => LeagueState::Complete, 'format' => 'Modern']);

    MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'deck_version_id' => $this->dv->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'format' => 'Modern',
        'started_at' => '2026-04-01 10:00:00',
        'ended_at' => '2026-04-01 10:14:00',
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]));

    expect($runs[0]['matches'][0]['durationSeconds'])->toBe(14 * 60);
});
