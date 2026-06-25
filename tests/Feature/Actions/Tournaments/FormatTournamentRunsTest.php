<?php

use App\Actions\Tournaments\FormatTournamentRuns;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns empty array when given empty collection', function () {
    expect(FormatTournamentRuns::run(collect(), new Deck))->toBe([]);
});

it('formats a tournament with derived W-L and per-match data', function () {
    $deck = Deck::factory()->create(['name' => 'Tron']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'name' => 'Modern Challenge 32',
        'format' => 'CMODERN',
        'started_at' => now()->subHours(3),
    ]);
    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'tournament_round' => 1,
        'outcome' => MatchOutcome::Win,
        'state' => MatchState::Complete,
        'started_at' => now()->subHours(3),
        'ended_at' => now()->subHours(3)->addMinutes(15),
    ]);
    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'tournament_round' => 2,
        'outcome' => MatchOutcome::Loss,
        'state' => MatchState::Complete,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2)->addMinutes(20),
    ]);

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    expect($row['id'])->toBe($tournament->id);
    expect($row['name'])->toBe('Modern Challenge 32');
    expect($row['format'])->toBe(MtgoMatch::displayFormat('CMODERN'));
    expect($row['results'])->toBe(['W', 'L']);
    expect($row['matches'])->toHaveCount(2);
    expect($row['matches'][0])->toHaveKeys([
        'id', 'result', 'opponentName', 'opponentArchetype', 'gameResults',
        'startedAt', 'startedAtHuman', 'durationSeconds', 'roundNumber',
    ]);
    expect($row['matches'][0]['result'])->toBe('W');
    expect($row['matches'][0]['roundNumber'])->toBe(1);
    expect($row['matches'][1]['roundNumber'])->toBe(2);
    expect($row)->toHaveKeys([
        'deck', 'versionLabel', 'gameWins', 'gameLosses',
        'onPlayRecord', 'onDrawRecord', 'topMatchups',
        'avgMatchSeconds', 'startedAt', 'startedAtHuman', 'topOpponentArchetype',
    ]);
    expect($row['avgMatchSeconds'])->toBeGreaterThan(0);
    // Legacy shape keys preserved for existing callers
    expect($row['wins'])->toBe(1);
    expect($row['losses'])->toBe(1);
    expect($row['matches_count'])->toBe(2);
});

it('does not crash when the tournament format is null', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'format' => null,
    ]);
    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'outcome' => MatchOutcome::Win,
        'state' => MatchState::Complete,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2)->addMinutes(10),
    ]);

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows[0]['format'])->toBe('');
});

it('returns results array with no padding (length = matches played)', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'format' => 'CMODERN',
    ]);
    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'outcome' => MatchOutcome::Win,
        'state' => MatchState::Complete,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2)->addMinutes(10),
    ]);

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows[0]['results'])->toBe(['W']);
    expect($rows[0]['results'])->toHaveCount(1);
});

it('computes average match duration in seconds', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'format' => 'CMODERN',
    ]);

    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'started_at' => '2026-04-01 10:00:00',
        'ended_at' => '2026-04-01 10:10:00',
    ]);
    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
        'started_at' => '2026-04-01 11:00:00',
        'ended_at' => '2026-04-01 11:20:00',
    ]);

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows[0]['avgMatchSeconds'])->toBe(900);
});

it('aggregates per-game wins/losses and on-play/draw record', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'format' => 'CMODERN',
    ]);

    $match = MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $games = [
        ['won' => 1, 'local_on_play' => 1],
        ['won' => 0, 'local_on_play' => 0],
        ['won' => 1, 'local_on_play' => 1],
    ];

    foreach ($games as $i => $g) {
        Game::factory()->create([
            'match_id' => $match->id,
            'won' => $g['won'],
            'local_on_play' => $g['local_on_play'],
            'started_at' => now()->addSeconds($i),
        ]);
    }

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows[0]['gameWins'])->toBe(2)
        ->and($rows[0]['gameLosses'])->toBe(1)
        ->and($rows[0]['onPlayRecord'])->toBe(['wins' => 2, 'losses' => 0])
        ->and($rows[0]['onDrawRecord'])->toBe(['wins' => 0, 'losses' => 1]);
});

it('computes top opponent archetype and top matchups list', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'format' => 'CMODERN',
    ]);

    $matches = collect();
    foreach (range(0, 3) as $i) {
        $opponent = Opponent::factory()->create();
        $matches->push(MtgoMatch::factory()->create([
            'tournament_id' => $tournament->id,
            'deck_version_id' => $version->id,
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'started_at' => now()->subMinutes(60 - $i * 10),
            'ended_at' => now()->subMinutes(50 - $i * 10),
            'opponent_id' => $opponent->id,
        ]));
    }

    $yawg = Archetype::factory()->create(['name' => 'Yawgmoth']);
    $burn = Archetype::factory()->create(['name' => 'Burn']);
    $hammer = Archetype::factory()->create(['name' => 'Hammer Time']);

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
            'is_opponent' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows[0]['topOpponentArchetype'])->toBe('Yawgmoth')
        ->and($rows[0]['topMatchups'])->toHaveCount(3)
        ->and($rows[0]['topMatchups'][0]['archetype'])->toBe('Yawgmoth')
        ->and($rows[0]['topMatchups'][0]['wins'])->toBe(1)
        ->and($rows[0]['topMatchups'][0]['losses'])->toBe(1);
});

it('exposes per-match durationSeconds and roundNumber', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $tournament = Tournament::factory()->create([
        'format' => 'CMODERN',
    ]);

    MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'deck_version_id' => $version->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'tournament_round' => 3,
        'started_at' => '2026-04-01 10:00:00',
        'ended_at' => '2026-04-01 10:14:00',
    ]);

    $rows = FormatTournamentRuns::run(Tournament::query()->get(), $deck);

    expect($rows[0]['matches'][0]['durationSeconds'])->toBe(14 * 60);
    expect($rows[0]['matches'][0]['roundNumber'])->toBe(3);
});
