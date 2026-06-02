<?php

use App\Actions\Cards\ComputeDrawOdds;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signatureFor(array $rows): string
{
    // $rows: [[mtgoId, qty, sideboardFlag], ...]
    $sig = collect($rows)
        ->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")
        ->implode('|');

    return base64_encode($sig);
}

it('returns null when the match has no deck version', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '400001',
        'token' => 'mt-d1',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    expect(ComputeDrawOdds::run($match))->toBeNull();
});

it('computes full-deck draw chances when there is no game timeline', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', '0'], ['102', '4', '0']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400002',
        'token' => 'mt-d2',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
        'deck_version_id' => $deckVersion->id,
    ]);

    $result = ComputeDrawOdds::run($match);

    expect($result)->not->toBeNull();
    expect($result->librarySize)->toBe(24);

    $bolt = collect($result->cards->all())->firstWhere('name', 'Lightning Bolt');
    expect($bolt->remaining)->toBe(4);
    expect($bolt->total)->toBe(4);
    expect(round($bolt->drawChance, 4))->toBe(round(4 / 24, 4));
});

it('subtracts cards the local player has moved out of library', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', '0'], ['102', '4', '0']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400003', 'token' => 'mt-d3', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-d3', 'started_at' => now()]);
    $local = Player::create(['username' => 'me']);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 1]);

    // Latest snapshot: 2 Bolts in hand, 1 Mountain on battlefield (all owner=1, not Library).
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '10:00:05',
        'content' => [
            'Players' => [['Id' => 1, 'HandCount' => 2, 'LibraryCount' => 21]],
            'Cards' => [
                ['Id' => 901, 'CatalogID' => 102, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 902, 'CatalogID' => 102, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 903, 'CatalogID' => 101, 'Owner' => 1, 'Zone' => 'Battlefield'],
                // A card still in library must NOT be subtracted:
                ['Id' => 904, 'CatalogID' => 101, 'Owner' => 1, 'Zone' => 'Library'],
                // Opponent's card must be ignored:
                ['Id' => 905, 'CatalogID' => 102, 'Owner' => 2, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    $result = ComputeDrawOdds::run($match);

    $bolt = collect($result->cards->all())->firstWhere('name', 'Lightning Bolt');
    $mountain = collect($result->cards->all())->firstWhere('name', 'Mountain');

    expect($bolt->remaining)->toBe(2);     // 4 − 2 in hand
    expect($bolt->total)->toBe(4);
    expect($mountain->remaining)->toBe(19); // 20 − 1 on battlefield
    expect($result->librarySize)->toBe(21); // 2 + 19
    expect($result->liveLibraryCount)->toBe(21); // snapshot LibraryCount for local player
});
