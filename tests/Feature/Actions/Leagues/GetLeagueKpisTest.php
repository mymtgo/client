<?php

use App\Actions\Leagues\GetLeagueKpis;
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

function seedLeagueRun(DeckVersion $dv, LeagueState $state, int $wins, int $losses): League
{
    $league = League::factory()->for($dv)->create(['state' => $state, 'format' => 'Modern']);

    $outcomes = array_merge(
        array_fill(0, $wins, MatchOutcome::Win),
        array_fill(0, $losses, MatchOutcome::Loss),
    );

    foreach ($outcomes as $i => $outcome) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $dv->id,
            'state' => MatchState::Complete,
            'outcome' => $outcome,
            'format' => 'Modern',
            'started_at' => now()->subMinutes(60 - $i),
            'ended_at' => now()->subMinutes(50 - $i),
        ]);
    }

    return $league;
}

it('returns counts, trophy rate, cash rate, avg finish', function () {
    seedLeagueRun($this->dv, LeagueState::Complete, 5, 0);
    seedLeagueRun($this->dv, LeagueState::Complete, 4, 1);
    seedLeagueRun($this->dv, LeagueState::Complete, 3, 2);
    seedLeagueRun($this->dv, LeagueState::Active, 1, 0);

    $kpis = GetLeagueKpis::run(League::query());

    expect($kpis['runs']['total'])->toBe(4)
        ->and($kpis['runs']['completed'])->toBe(3)
        ->and($kpis['runs']['live'])->toBe(1)
        ->and($kpis['trophies'])->toBe(1)
        ->and($kpis['trophyRate'])->toBe(33.0)
        ->and($kpis['cashRate'])->toBe(67.0)
        ->and($kpis['avgFinish'])->toBe(4.0);
});

it('counts dropped runs as completed for stats', function () {
    seedLeagueRun($this->dv, LeagueState::Complete, 5, 0);
    seedLeagueRun($this->dv, LeagueState::Dropped, 2, 2);

    $kpis = GetLeagueKpis::run(League::query());

    expect($kpis['runs']['completed'])->toBe(2)
        ->and($kpis['trophies'])->toBe(1)
        ->and($kpis['trophyRate'])->toBe(50.0)
        ->and($kpis['avgFinish'])->toBe(3.5);
});

it('returns null rates and avg when no completed runs', function () {
    seedLeagueRun($this->dv, LeagueState::Active, 1, 0);

    $kpis = GetLeagueKpis::run(League::query());

    expect($kpis['trophyRate'])->toBeNull()
        ->and($kpis['cashRate'])->toBeNull()
        ->and($kpis['avgFinish'])->toBeNull();
});

it('returns top matchup with record and play count', function () {
    $league = seedLeagueRun($this->dv, LeagueState::Complete, 3, 0);

    $arch = Archetype::factory()->create(['name' => 'Yawgmoth']);
    $players = Player::factory()->count(3)->create();

    foreach ($league->matches->take(3) as $i => $match) {
        DB::table('match_archetypes')->insert([
            'mtgo_match_id' => $match->id,
            'archetype_id' => $arch->id,
            'player_id' => $players[$i]->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $game = Game::factory()->create([
            'match_id' => $match->id,
            'won' => 1,
            'started_at' => now(),
        ]);

        DB::table('game_player')->insert([
            'game_id' => $game->id,
            'player_id' => $players[$i]->id,
            'is_local' => false,
            'instance_id' => 0,
        ]);
    }

    $kpis = GetLeagueKpis::run(League::query());

    expect($kpis['topMatchup'])->not->toBeNull()
        ->and($kpis['topMatchup']['archetype'])->toBe('Yawgmoth')
        ->and($kpis['topMatchup']['wins'])->toBe(3)
        ->and($kpis['topMatchup']['losses'])->toBe(0)
        ->and($kpis['topMatchup']['count'])->toBe(3);
});

it('returns deck count', function () {
    $deck2 = Deck::factory()->create();
    $dv2 = DeckVersion::factory()->for($deck2)->create();

    seedLeagueRun($this->dv, LeagueState::Complete, 5, 0);
    seedLeagueRun($dv2, LeagueState::Complete, 4, 1);

    $kpis = GetLeagueKpis::run(League::query());

    expect($kpis['runs']['decks'])->toBe(2);
});
