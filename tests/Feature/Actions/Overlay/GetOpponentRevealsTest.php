<?php

use App\Actions\Overlay\GetOpponentReveals;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function revealsMatch(): MtgoMatch
{
    return MtgoMatch::create([
        'mtgo_id' => '900001',
        'token' => 'mt-reveals',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);
}

function revealsGame(MtgoMatch $match, array $opponentDeckJson, string $gameId = 'g-r1'): Game
{
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => $gameId, 'started_at' => now()]);

    $local = Player::firstOrCreate(['username' => 'me']);
    $opponent = Player::firstOrCreate(['username' => 'opp']);

    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 'i-1']);
    // deck_json passes through the GamePlayer pivot's 'array' cast — plain
    // array here, never json_encode (double-encoding breaks the read side).
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2', 'deck_json' => $opponentDeckJson]);

    return $game;
}

it('returns revealed opponent cards with resolved metadata', function () {
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant', 'color_identity' => 'R']);

    $match = revealsMatch();
    revealsGame($match, [['mtgo_id' => 102, 'quantity' => 2]]);

    $reveals = GetOpponentReveals::run($match);

    expect($reveals)->toHaveCount(1);
    expect($reveals->first()->name)->toBe('Lightning Bolt');
    expect($reveals->first()->type)->toBe('Instant');
    expect($reveals->first()->quantity)->toBe(2);
});

it('aggregates reveals across games and caps at four copies', function () {
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $match = revealsMatch();
    revealsGame($match, [['mtgo_id' => 102, 'quantity' => 3]], 'g-r1');
    revealsGame($match, [['mtgo_id' => 102, 'quantity' => 3]], 'g-r2');

    $reveals = GetOpponentReveals::run($match);

    expect($reveals)->toHaveCount(1);
    expect($reveals->first()->quantity)->toBe(4);
});

it('merges different printings of the same card by name', function () {
    Card::create(['mtgo_id' => '301', 'oracle_id' => 'o-mine', 'name' => "Urza's Mine", 'type' => 'Land']);
    Card::create(['mtgo_id' => '302', 'oracle_id' => 'o-mine', 'name' => "Urza's Mine", 'type' => 'Land']);

    $match = revealsMatch();
    revealsGame($match, [
        ['mtgo_id' => 301, 'quantity' => 1],
        ['mtgo_id' => 302, 'quantity' => 1],
    ]);

    $reveals = GetOpponentReveals::run($match);

    expect($reveals)->toHaveCount(1);
    expect($reveals->first()->name)->toBe("Urza's Mine");
    expect($reveals->first()->quantity)->toBe(2);
});

it('returns an empty collection when nothing has been revealed', function () {
    $match = revealsMatch();
    revealsGame($match, []);

    expect(GetOpponentReveals::run($match))->toHaveCount(0);
});

it('ignores the local player deck', function () {
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $match = revealsMatch();
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-r-local', 'started_at' => now()]);
    $local = Player::firstOrCreate(['username' => 'me']);
    $game->players()->attach($local->id, [
        'is_local' => 1,
        'instance_id' => 'i-1',
        'deck_json' => [['mtgo_id' => 102, 'quantity' => 4]],
    ]);

    expect(GetOpponentReveals::run($match))->toHaveCount(0);
});

it('includes the art crop in the payload', function () {
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant', 'art_crop' => 'https://img/bolt-art.jpg']);

    $match = revealsMatch();
    revealsGame($match, [['mtgo_id' => 102, 'quantity' => 1]]);

    expect(GetOpponentReveals::run($match)->first()->artCrop)->toBe('https://img/bolt-art.jpg');
});
