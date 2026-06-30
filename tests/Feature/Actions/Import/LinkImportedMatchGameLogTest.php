<?php

use App\Actions\Import\LinkImportedMatchGameLog;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function limgl_makeImportedMatch(string $token, string $startedAt, string $opponentName): MtgoMatch
{
    $match = MtgoMatch::factory()->create([
        'token' => $token,
        'imported' => true,
        'state' => 'complete',
        'started_at' => $startedAt,
    ]);

    $local = Player::firstOrCreate(['username' => 'localplayer']);
    $opponent = Player::firstOrCreate(['username' => $opponentName]);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => $startedAt,
    ]);
    $game->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false]);

    return $match;
}

it('relinks an imported match to its orphan game log by opponent and start time', function () {
    $match = limgl_makeImportedMatch('match-token-1', '2026-01-13 15:35:29', 'NorinTheScary');

    // Matching orphan log: opponent present, first_timestamp within 5 minutes.
    $log = GameLog::create([
        'match_token' => 'original-dat-token',
        'file_path' => '/logs/a.dat',
        'first_timestamp' => '2026-01-13 15:35:42',
        'players' => ['localplayer', 'NorinTheScary'],
        'decoded_entries' => [['timestamp' => '2026-01-13T15:35:42+00:00', 'message' => '@Pfoo']],
    ]);

    // Decoy: right time window, wrong opponent.
    GameLog::create([
        'match_token' => 'decoy-token',
        'file_path' => '/logs/b.dat',
        'first_timestamp' => '2026-01-13 15:36:10',
        'players' => ['localplayer', 'SomeoneElse'],
        'decoded_entries' => [['timestamp' => '2026-01-13T15:36:10+00:00', 'message' => '@Pbar']],
    ]);

    $result = LinkImportedMatchGameLog::run($match);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($log->id);
    expect($log->fresh()->match_token)->toBe('match-token-1');
});

it('picks the closest game log when several share the opponent and window', function () {
    $match = limgl_makeImportedMatch('match-token-2', '2026-01-14 15:39:47', 'Raydon');

    GameLog::create([
        'match_token' => 'far-token',
        'file_path' => '/logs/far.dat',
        'first_timestamp' => '2026-01-14 15:43:40', // ~233s away
        'players' => ['localplayer', 'Raydon'],
        'decoded_entries' => [['timestamp' => '2026-01-14T15:43:40+00:00', 'message' => '@Pfar']],
    ]);

    $near = GameLog::create([
        'match_token' => 'near-token',
        'file_path' => '/logs/near.dat',
        'first_timestamp' => '2026-01-14 15:40:01', // ~14s away
        'players' => ['localplayer', 'Raydon'],
        'decoded_entries' => [['timestamp' => '2026-01-14T15:40:01+00:00', 'message' => '@Pnear']],
    ]);

    $result = LinkImportedMatchGameLog::run($match);

    expect($result->id)->toBe($near->id);
    expect($near->fresh()->match_token)->toBe('match-token-2');
});

it('returns the already-linked log without consuming another orphan', function () {
    $match = limgl_makeImportedMatch('match-token-3', '2026-01-15 12:00:00', 'Lachisula');

    $linked = GameLog::create([
        'match_token' => 'match-token-3', // already keyed to the match
        'file_path' => '/logs/linked.dat',
        'first_timestamp' => '2026-01-15 12:00:05',
        'players' => ['localplayer', 'Lachisula'],
        'decoded_entries' => [['timestamp' => '2026-01-15T12:00:05+00:00', 'message' => '@Plinked']],
    ]);

    // An orphan that also matches — must NOT be consumed since one is already linked.
    $orphan = GameLog::create([
        'match_token' => 'orphan-token',
        'file_path' => '/logs/orphan.dat',
        'first_timestamp' => '2026-01-15 12:00:10',
        'players' => ['localplayer', 'Lachisula'],
        'decoded_entries' => [['timestamp' => '2026-01-15T12:00:10+00:00', 'message' => '@Porphan']],
    ]);

    $result = LinkImportedMatchGameLog::run($match);

    expect($result->id)->toBe($linked->id);
    expect($orphan->fresh()->match_token)->toBe('orphan-token');
});

it('returns null when no orphan log matches the opponent within the time window', function () {
    $match = limgl_makeImportedMatch('match-token-4', '2026-01-16 10:00:00', 'Ghost');

    // Same opponent, but well outside the 5-minute window.
    GameLog::create([
        'match_token' => 'late-token',
        'file_path' => '/logs/late.dat',
        'first_timestamp' => '2026-01-16 10:30:00',
        'players' => ['localplayer', 'Ghost'],
        'decoded_entries' => [['timestamp' => '2026-01-16T10:30:00+00:00', 'message' => '@Plate']],
    ]);

    $result = LinkImportedMatchGameLog::run($match);

    expect($result)->toBeNull();
});
