<?php

use App\Actions\DetermineMatchArchetypes;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);
});

it('writes two match_archetypes rows — is_opponent=false for local and is_opponent=true for opponent', function (): void {
    $account = Account::factory()->create();
    $opponent = Opponent::factory()->create();

    // Build archetypes with distinct cards so local detection works for both.
    $localArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $localCard = Card::factory()->create(['mtgo_id' => 88001, 'oracle_id' => 'oracle-local-001']);
    $localDeck = ArchetypeDeck::factory()->for($localArchetype)->create();
    $localDeck->cards()->attach($localCard->id, ['quantity' => 4, 'sideboard' => false]);

    $opponentArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $opponentCard = Card::factory()->create(['mtgo_id' => 88002, 'oracle_id' => 'oracle-opp-001']);
    $opponentDeck = ArchetypeDeck::factory()->for($opponentArchetype)->create();
    $opponentDeck->cards()->attach($opponentCard->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::factory()->create([
        'format' => 'modern',
        'account_id' => $account->id,
        'opponent_id' => $opponent->id,
    ]);

    $game = Game::factory()->create(['match_id' => $match->id]);

    GameDeck::factory()->create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => [['mtgo_id' => 88001, 'quantity' => 4]],
    ]);

    GameDeck::factory()->create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [['mtgo_id' => 88002, 'quantity' => 4]],
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games']));

    $rows = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->get();

    expect($rows)->toHaveCount(2);

    $localRow = $rows->firstWhere('is_opponent', 0);
    $opponentRow = $rows->firstWhere('is_opponent', 1);

    expect($localRow)->not->toBeNull();
    expect($opponentRow)->not->toBeNull();

    expect((int) $localRow->archetype_id)->toBe($localArchetype->id);
    expect((int) $opponentRow->archetype_id)->toBe($opponentArchetype->id);
});

it('uses the deckVersion archetype for the local row when a deck version is linked', function (): void {
    $account = Account::factory()->create();
    $opponent = Opponent::factory()->create();

    $linkedArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $opponentArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $opponentCard = Card::factory()->create(['mtgo_id' => 88003, 'oracle_id' => 'oracle-opp-002']);
    $opponentDeck = ArchetypeDeck::factory()->for($opponentArchetype)->create();
    $opponentDeck->cards()->attach($opponentCard->id, ['quantity' => 4, 'sideboard' => false]);

    $deck = Deck::factory()->create(['archetype_id' => $linkedArchetype->id]);
    $deckVersion = DeckVersion::factory()->for($deck)->create();

    $match = MtgoMatch::factory()->create([
        'format' => 'modern',
        'account_id' => $account->id,
        'opponent_id' => $opponent->id,
        'deck_version_id' => $deckVersion->id,
    ]);

    $game = Game::factory()->create(['match_id' => $match->id]);

    GameDeck::factory()->create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => [],
    ]);

    GameDeck::factory()->create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [['mtgo_id' => 88003, 'quantity' => 4]],
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games', 'deckVersion.deck']));

    $localRow = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->where('is_opponent', false)
        ->first();

    expect($localRow)->not->toBeNull();
    expect((int) $localRow->archetype_id)->toBe($linkedArchetype->id);
    expect((float) $localRow->confidence)->toBe(1.0);
});

it('writes no local row when there are no games', function (): void {
    $match = MtgoMatch::factory()->create(['format' => 'modern']);

    DetermineMatchArchetypes::run($match->fresh(['games']));

    $rows = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->get();

    expect($rows)->toHaveCount(0);
});

it('aggregates opponent cards across multiple games into a single opponent archetype row', function (): void {
    $account = Account::factory()->create();
    $opponent = Opponent::factory()->create();

    $opponentArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $opponentCard = Card::factory()->create(['mtgo_id' => 88004, 'oracle_id' => 'oracle-opp-003']);
    $opponentDeck = ArchetypeDeck::factory()->for($opponentArchetype)->create();
    $opponentDeck->cards()->attach($opponentCard->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::factory()->create([
        'format' => 'modern',
        'account_id' => $account->id,
        'opponent_id' => $opponent->id,
    ]);

    // Two games, each with an opponent deck_json entry.
    $game1 = Game::factory()->create(['match_id' => $match->id]);
    $game2 = Game::factory()->create(['match_id' => $match->id]);

    GameDeck::factory()->create(['game_id' => $game1->id, 'is_opponent' => false, 'deck_json' => []]);
    GameDeck::factory()->create([
        'game_id' => $game1->id,
        'is_opponent' => true,
        'deck_json' => [['mtgo_id' => 88004, 'quantity' => 2]],
    ]);

    GameDeck::factory()->create(['game_id' => $game2->id, 'is_opponent' => false, 'deck_json' => []]);
    GameDeck::factory()->create([
        'game_id' => $game2->id,
        'is_opponent' => true,
        'deck_json' => [['mtgo_id' => 88004, 'quantity' => 2]],
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games']));

    $opponentRows = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
        ->get();

    // Must be exactly ONE opponent archetype row (1v1 invariant).
    expect($opponentRows)->toHaveCount(1);
    expect((int) $opponentRows->first()->archetype_id)->toBe($opponentArchetype->id);
});
