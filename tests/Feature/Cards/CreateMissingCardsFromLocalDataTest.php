<?php

use App\Actions\Cards\CreateMissingCardsFromLocalData;
use App\Actions\Decks\GenerateDeckSignature;
use App\Jobs\PopulateMissingCardData;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A deck version carrying a signature for the given catalog ids, written
 * the same way GenerateDeckSignature writes one, but with the cards table
 * left empty afterwards. That is exactly the state a cloud-sync import
 * leaves behind: signatures arrive pre-built, so nothing ever creates the
 * stubs.
 */
function deckVersionWithCards(array $mtgoIds): DeckVersion
{
    $cards = collect($mtgoIds)->map(fn (int $id) => [
        'mtgo_id' => $id,
        'quantity' => 4,
        'sideboard' => false,
    ]);

    $signature = GenerateDeckSignature::run($cards);

    // GenerateDeckSignature creates stubs on the way past; drop them so the
    // test starts from the post-import state it means to describe.
    Card::query()->delete();

    return DeckVersion::factory()->create([
        'deck_id' => Deck::factory()->create()->id,
        'signature' => $signature,
    ]);
}

beforeEach(function () {
    Queue::fake();
});

it('creates a stub for every catalog id in a deck version signature', function () {
    deckVersionWithCards([101, 202, 303]);

    expect(Card::query()->count())->toBe(0);

    $created = CreateMissingCardsFromLocalData::run();

    expect($created)->toBe(3)
        ->and(Card::query()->pluck('mtgo_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([101, 202, 303]);
});

it('creates stubs for cards only present in a registered game deck list', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()->create()->id]);
    $player = Player::factory()->create();

    $game->players()->attach($player->id, [
        'instance_id' => 1,
        'is_local' => true,
        'deck_json' => [
            ['mtgo_id' => 555, 'quantity' => 2, 'sideboard' => false],
            ['mtgo_id' => 666, 'quantity' => 1, 'sideboard' => true],
        ],
    ]);

    $created = CreateMissingCardsFromLocalData::run();

    expect($created)->toBe(2)
        ->and(Card::query()->pluck('mtgo_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([555, 666]);
});

it('creates stubs for tokens that only ever appear in a game timeline', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()->create()->id]);

    GameTimeline::query()->create([
        'game_id' => $game->id,
        'timestamp' => now(),
        'content' => [
            'Cards' => [
                ['CatalogID' => 777, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    CreateMissingCardsFromLocalData::run();

    expect(Card::query()->where('mtgo_id', 777)->exists())->toBeTrue();
});

it('leaves existing cards alone and is idempotent', function () {
    deckVersionWithCards([101, 202]);

    Card::query()->create(['mtgo_id' => 101, 'name' => 'Already Known']);

    $first = CreateMissingCardsFromLocalData::run();
    $second = CreateMissingCardsFromLocalData::run();

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(Card::query()->count())->toBe(2)
        ->and(Card::query()->where('mtgo_id', 101)->value('name'))->toBe('Already Known');
});

it('queues the detail fill when it creates anything', function () {
    deckVersionWithCards([101]);

    CreateMissingCardsFromLocalData::run();

    Queue::assertPushed(PopulateMissingCardData::class);
});

it('survives a malformed signature rather than aborting the scan', function () {
    DeckVersion::factory()->create([
        'deck_id' => Deck::factory()->create()->id,
        'signature' => 'not-valid-base64-!!!',
    ]);

    deckVersionWithCards([404]);

    $created = CreateMissingCardsFromLocalData::run();

    expect($created)->toBe(1)
        ->and(Card::query()->where('mtgo_id', 404)->exists())->toBeTrue();
});

/**
 * Base64 of anything decodes to something, so a signature that is not a
 * decklist still parses. Casting its first segment would invent catalog
 * ids: (int) '3f2a-...' is 3, and that stub would then be fetched and
 * stored as a real card.
 */
it('ignores signature entries whose catalog id is not numeric', function () {
    DeckVersion::factory()->create([
        'deck_id' => Deck::factory()->create()->id,
        'signature' => base64_encode('3f2ab9c1-dead-beef-cafe-000000000000:4:false'),
    ]);

    $created = CreateMissingCardsFromLocalData::run();

    expect($created)->toBe(0)
        ->and(Card::query()->count())->toBe(0);
});
