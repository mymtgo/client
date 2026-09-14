<?php

use App\Actions\Decks\GetBoardingSplit;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<bool>  $gameResults  Won/lost in the order the games were played.
 */
function boardingMatch(DeckVersion $version, array $gameResults, ?Carbon\Carbon $startedAt = null): MtgoMatch
{
    $startedAt ??= now()->subHour();

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'started_at' => $startedAt,
    ]);

    foreach ($gameResults as $index => $won) {
        Game::create([
            'match_id' => $match->id,
            'mtgo_id' => fake()->unique()->randomNumber(8),
            'started_at' => $startedAt->copy()->addMinutes($index * 10),
            'ended_at' => $startedAt->copy()->addMinutes($index * 10 + 5),
            'won' => $won,
        ]);
    }

    return $match;
}

function boardingDeck(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$deck, $version];
}

it('splits the first game of a match from the ones played after boarding', function () {
    [$deck, $version] = boardingDeck();

    boardingMatch($version, [false, true, true]);

    $split = GetBoardingSplit::run($deck, now()->subDay(), now());

    expect($split['gameOneWon'])->toBe(0)
        ->and($split['gameOneLost'])->toBe(1)
        ->and($split['gameOneRate'])->toBe(0)
        ->and($split['postBoardWon'])->toBe(2)
        ->and($split['postBoardLost'])->toBe(0)
        ->and($split['postBoardRate'])->toBe(100)
        ->and($split['delta'])->toBe(100)
        ->and($split['games'])->toBe(3);
});

it('orders games within a match by when they started, not by insertion', function () {
    [$deck, $version] = boardingDeck();

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHour(),
    ]);

    // The later game is inserted first, so an id-ordered query would call it game one.
    Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(15),
        'won' => true,
    ]);
    Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => now()->subMinutes(50),
        'ended_at' => now()->subMinutes(45),
        'won' => false,
    ]);

    $split = GetBoardingSplit::run($deck, now()->subDay(), now());

    expect($split['gameOneLost'])->toBe(1)
        ->and($split['postBoardWon'])->toBe(1);
});

it('counts each match separately rather than pooling all games', function () {
    [$deck, $version] = boardingDeck();

    boardingMatch($version, [true, false, false]);
    boardingMatch($version, [true, true]);

    $split = GetBoardingSplit::run($deck, now()->subDay(), now());

    expect($split['gameOneWon'])->toBe(2)
        ->and($split['gameOneLost'])->toBe(0)
        ->and($split['postBoardWon'])->toBe(1)
        ->and($split['postBoardLost'])->toBe(2)
        ->and($split['games'])->toBe(5);
});

it('ignores matches outside the range', function () {
    [$deck, $version] = boardingDeck();

    boardingMatch($version, [true, true], now()->subDays(40));

    $split = GetBoardingSplit::run($deck, now()->subDays(7), now());

    expect($split['games'])->toBe(0)
        ->and($split['delta'])->toBe(0);
});

it('narrows to a single deck version when one is given', function () {
    [$deck, $version] = boardingDeck();
    $other = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    boardingMatch($version, [true, true]);
    boardingMatch($other, [false, false]);

    $split = GetBoardingSplit::run($deck, now()->subDay(), now(), $version);

    expect($split['games'])->toBe(2)
        ->and($split['gameOneWon'])->toBe(1)
        ->and($split['postBoardWon'])->toBe(1);
});
