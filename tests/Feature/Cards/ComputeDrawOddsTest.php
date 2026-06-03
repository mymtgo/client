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
        'signature' => signatureFor([['101', '20', 'false'], ['102', '4', 'false']]),
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
        'signature' => signatureFor([['101', '20', 'false'], ['102', '4', 'false']]),
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

it('uses the latest timeline snapshot when multiple rows exist', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', 'false'], ['102', '4', 'false']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400007', 'token' => 'mt-d7', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-d7', 'started_at' => now()]);
    $local = Player::create(['username' => 'me']);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 1]);

    // Earlier snapshot: nothing out of library yet (inserted last to prove
    // selection is by timestamp, not insertion order).
    // Latest snapshot: 1 Bolt in hand, 1 Mountain on battlefield.
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '10:00:09',
        'content' => [
            'Players' => [['Id' => 1, 'LibraryCount' => 22]],
            'Cards' => [
                ['Id' => 901, 'CatalogID' => 102, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 903, 'CatalogID' => 101, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '10:00:01',
        'content' => [
            'Players' => [['Id' => 1, 'LibraryCount' => 24]],
            'Cards' => [],
        ],
    ]);

    $result = ComputeDrawOdds::run($match);

    $bolt = collect($result->cards->all())->firstWhere('name', 'Lightning Bolt');
    $mountain = collect($result->cards->all())->firstWhere('name', 'Mountain');

    expect($bolt->remaining)->toBe(3);      // 4 − 1 in hand (latest snapshot)
    expect($mountain->remaining)->toBe(19); // 20 − 1 on battlefield (latest snapshot)
    expect($result->librarySize)->toBe(22); // 3 + 19
    expect($result->liveLibraryCount)->toBe(22); // latest snapshot LibraryCount
});

it('computes top-5 type probabilities as P(>=1 in 5)', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        // 15 lands, 5 instants => 20-card library, no timeline.
        'signature' => signatureFor([['101', '15', 'false'], ['102', '5', 'false']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400004', 'token' => 'mt-d4', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $result = ComputeDrawOdds::run($match);

    $land = collect($result->topFive->all())->firstWhere('type', 'Land');
    $instant = collect($result->topFive->all())->firstWhere('type', 'Instant');

    // P(>=1 land in 5) = 1 - C(5,5)/C(20,5) = 1 - (5*4*3*2*1)/(20*19*18*17*16)
    $expectedLand = 1 - (5 * 4 * 3 * 2 * 1) / (20 * 19 * 18 * 17 * 16);
    // P(>=1 instant in 5) = 1 - prod_{i=0..4} (15-i)/(20-i)
    $expectedInstant = 1 - ((15 / 20) * (14 / 19) * (13 / 18) * (12 / 17) * (11 / 16));

    expect($result->librarySize)->toBe(20);
    expect(round($land->probability, 6))->toBe(round($expectedLand, 6));     // ~0.99997
    expect(round($instant->probability, 6))->toBe(round($expectedInstant, 6)); // ~0.8056
});

it('excludes sideboard cards (real "true"/"false" string flags)', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '301', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        // Maindeck land (false) + sideboard card (true) — the real signature format.
        'signature' => signatureFor([['101', '20', 'false'], ['301', '3', 'true']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400006', 'token' => 'mt-d6', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $result = ComputeDrawOdds::run($match);

    // Maindeck must resolve (regression: "false" string was truthy, dropping every card).
    expect($result)->not->toBeNull();
    expect($result->librarySize)->toBe(20);

    $names = collect($result->cards->all())->pluck('name');
    expect($names)->toContain('Mountain');
    expect($names)->not->toContain('Rest in Peace');
});

it('prefers per-game deck_json over the match deck version', function () {
    // Match deck version: 20 Mountains, 4 Bolts in main; 3 RIP in side.
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    Card::create(['mtgo_id' => '301', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', 'false'], ['102', '4', 'false'], ['301', '3', 'true']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400008', 'token' => 'mt-d8', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    // Game 2 deck_json: sided in 3 RIP for 3 Bolts. So maindeck = 20 Mountain + 1 Bolt + 3 RIP,
    // sideboard zone = 3 Bolts.
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-d8', 'started_at' => now()]);
    $local = Player::create(['username' => 'me']);
    $game->players()->attach($local->id, [
        'is_local' => 1,
        'instance_id' => 1,
        'deck_json' => [
            ['mtgo_id' => '101', 'quantity' => 20, 'sideboard' => false],
            ['mtgo_id' => '102', 'quantity' => 1, 'sideboard' => false],
            ['mtgo_id' => '102', 'quantity' => 3, 'sideboard' => true],
            ['mtgo_id' => '301', 'quantity' => 3, 'sideboard' => false],
        ],
    ]);

    $result = ComputeDrawOdds::run($match);

    expect($result)->not->toBeNull();
    expect($result->librarySize)->toBe(24); // 20 + 1 + 3

    $names = collect($result->cards->all())->pluck('name');
    expect($names)->toContain('Rest in Peace'); // sided in this game

    $bolt = collect($result->cards->all())->firstWhere('name', 'Lightning Bolt');
    expect($bolt->total)->toBe(1); // only the 1 maindeck copy, not the 3 in sideboard zone
});

it('does not subtract stack entries (activated abilities) from remaining', function () {
    // Lembas: 4 in deck, 1 on battlefield. Its activated ability sits on the
    // Stack with the same CatalogID — that must NOT count as "seen outside".
    Card::create(['mtgo_id' => '501', 'oracle_id' => 'o-lembas', 'name' => 'Lembas', 'type' => 'Artifact - Food']);
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', 'false'], ['501', '4', 'false']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400010', 'token' => 'mt-d10', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-d10', 'started_at' => now()]);
    $local = Player::create(['username' => 'me']);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 1]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '10:00:00',
        'content' => [
            'Players' => [['Id' => 1, 'LibraryCount' => 23]],
            'Cards' => [
                ['Id' => 800, 'CatalogID' => 501, 'Owner' => 1, 'Zone' => 'Battlefield'],
                // Activated ability on the stack — same CatalogID, must be ignored.
                ['Id' => 801, 'CatalogID' => 501, 'Owner' => 1, 'Zone' => 'Stack'],
            ],
        ],
    ]);

    $result = ComputeDrawOdds::run($match);
    $lembas = collect($result->cards->all())->firstWhere('name', 'Lembas');

    expect($lembas->total)->toBe(4);
    expect($lembas->remaining)->toBe(3); // 4 - 1 on battlefield; stack entry ignored
});

it('does not subtract sideboard-zone copies from remaining', function () {
    // Maindeck has 2 Nihil Spellbombs; sideboard has 1. The SB-zone copy must
    // NOT be counted as "seen outside library" — it was never in the library.
    Card::create(['mtgo_id' => '401', 'oracle_id' => 'o-nihil', 'name' => 'Nihil Spellbomb', 'type' => 'Artifact']);
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', 'false'], ['401', '2', 'false'], ['401', '1', 'true']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400009', 'token' => 'mt-d9', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-d9', 'started_at' => now()]);
    $local = Player::create(['username' => 'me']);
    $game->players()->attach($local->id, [
        'is_local' => 1,
        'instance_id' => 1,
        'deck_json' => [
            ['mtgo_id' => '101', 'quantity' => 20, 'sideboard' => false],
            ['mtgo_id' => '401', 'quantity' => 2, 'sideboard' => false],
            ['mtgo_id' => '401', 'quantity' => 1, 'sideboard' => true],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '10:00:00',
        'content' => [
            'Players' => [['Id' => 1, 'LibraryCount' => 22]],
            'Cards' => [
                // The sideboard-zone Nihil — must be ignored by the remaining calc.
                ['Id' => 700, 'CatalogID' => 401, 'Owner' => 1, 'Zone' => 'Sideboard'],
            ],
        ],
    ]);

    $result = ComputeDrawOdds::run($match);
    $nihil = collect($result->cards->all())->firstWhere('name', 'Nihil Spellbomb');

    expect($nihil->total)->toBe(2);
    expect($nihil->remaining)->toBe(2); // would be 1 if SB-zone copy was wrongly subtracted
});

it('includes card identity, image, and mtgoId in the payload', function () {
    Card::create([
        'mtgo_id' => '201', 'oracle_id' => 'o-x', 'name' => 'Snapcaster Mage',
        'type' => 'Creature', 'color_identity' => 'U', 'image' => 'https://img/snap.jpg',
    ]);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['201', '4', 'false']]),
        'modified_at' => now(),
    ]);
    $match = MtgoMatch::create([
        'mtgo_id' => '400005', 'token' => 'mt-d5', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $card = collect(ComputeDrawOdds::run($match)->cards->all())->firstWhere('name', 'Snapcaster Mage');

    expect($card->mtgoId)->toBe(201);
    expect($card->identity)->toBe('U');
    expect($card->image)->toBe('https://img/snap.jpg');
});
