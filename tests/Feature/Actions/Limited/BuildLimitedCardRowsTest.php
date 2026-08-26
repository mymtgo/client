<?php

use App\Actions\Limited\Read\BuildLimitedCardRows;
use App\Enums\LeagueKind;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('builds one row per drafted card with status, seen and wheel facts', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_count' => 8]);
    Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'bard', 'name' => 'Bard', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '2', 'oracle_id' => 'harper', 'name' => 'Harper', 'type' => 'Creature']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'pack_id' => 500, 'cards_available' => [1, 2], 'picked_catalog_id' => 1]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 9, 'pack_number' => 1, 'pick_number' => 9, 'pack_id' => 500, 'cards_available' => [2], 'picked_catalog_id' => 2]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 21, 'pack_number' => 2, 'pick_number' => 7, 'pack_id' => 600, 'cards_available' => [1], 'picked_catalog_id' => 1]);
    LimitedDeckSnapshot::create(['league_id' => $league->id, 'source' => 'registered', 'signature' => 's', 'captured_at' => now(), 'cards' => [['catalog_id' => 1, 'quantity' => 2, 'sideboard' => false]]]);

    $result = BuildLimitedCardRows::run($league);

    expect($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0])->toMatchArray(['catalogId' => 1, 'oracleId' => 'bard', 'ordinals' => [1, 21], 'labels' => ['P1p1', 'P2p7'], 'status' => 'main', 'gamesCast' => 0, 'castWon' => 0, 'castLost' => 0, 'winPctCast' => null, 'seenCount' => 2, 'wheeled' => false])
        ->and($result['rows'][1])->toMatchArray(['catalogId' => 2, 'ordinals' => [9], 'labels' => ['P1p9'], 'status' => 'cut', 'seenCount' => 2, 'wheeled' => true])
        ->and($result['summary'])->toMatchArray(['distinct' => 2, 'games' => 0, 'otherDrafts' => 0])
        ->and($result['cards']['1']->name)->toBe('Bard');
});

it('reports every drafted card as pool while nothing is registered yet', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_count' => 8]);
    Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'bard', 'name' => 'Bard', 'type' => 'Creature']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'cards_available' => [1], 'picked_catalog_id' => 1]);

    $result = BuildLimitedCardRows::run($league);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['status'])->toBe('pool');
});

it('returns an empty payload for a league with no draft', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'set_code' => 'HOB', 'started_at' => now()]);

    expect(BuildLimitedCardRows::run($league))->toBe([
        'rows' => [],
        'summary' => ['distinct' => 0, 'games' => 0, 'otherDrafts' => 0],
        'cards' => [],
    ]);
});

it('carries prior draft facts and counts the other drafts they came from', function () {
    Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'harper', 'set_code' => 'HOB', 'name' => 'Harper', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '2', 'oracle_id' => 'bard', 'set_code' => 'HOB', 'name' => 'Bard', 'type' => 'Creature']);

    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_count' => 8]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'cards_available' => [1, 2], 'picked_catalog_id' => 1]);

    $other = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subDay()]);
    $otherDraft = Draft::factory()->finished()->create(['league_id' => $other->id, 'seat_count' => 8]);
    DraftPick::factory()->create(['draft_id' => $otherDraft->id, 'ordinal' => 3, 'pack_number' => 1, 'pick_number' => 3, 'cards_available' => [1, 2], 'picked_catalog_id' => 1]);

    $result = BuildLimitedCardRows::run($league);

    expect($result['rows'][0])->toMatchArray(['catalogId' => 1, 'priorTaken' => 1, 'priorAvgOrdinal' => 3.0, 'priorWheeled' => 0, 'priorDrafts' => 1])
        ->and($result['summary']['otherDrafts'])->toBe(1);
});

it('reads cast game stats from the synthetic limited deck versions', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_count' => 8]);
    $card = Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'bard', 'name' => 'Bard', 'type' => 'Creature']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'cards_available' => [1], 'picked_catalog_id' => 1]);

    $deck = Deck::factory()->create(['mtgo_id' => "limited:{$draft->draft_token}"]);
    $version = DeckVersion::factory()->for($deck)->create();
    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => MatchState::Complete]);
    $won = Game::factory()->for($match, 'match')->create(['won' => true]);
    $lost = Game::factory()->for($match, 'match')->create(['won' => false]);

    DB::table('card_game_stats')->insert([
        ['deck_version_id' => $version->id, 'game_id' => $won->id, 'oracle_id' => $card->oracle_id, 'quantity' => 1, 'kept' => 1, 'seen' => 1, 'cast' => 1, 'won' => true, 'is_postboard' => false, 'sided_out' => false, 'opponent' => false],
        ['deck_version_id' => $version->id, 'game_id' => $lost->id, 'oracle_id' => $card->oracle_id, 'quantity' => 1, 'kept' => 1, 'seen' => 1, 'cast' => 2, 'won' => false, 'is_postboard' => false, 'sided_out' => false, 'opponent' => false],
    ]);

    $result = BuildLimitedCardRows::run($league);

    expect($result['rows'][0])->toMatchArray(['gamesCast' => 2, 'castWon' => 1, 'castLost' => 1, 'winPctCast' => 50])
        ->and($result['summary']['games'])->toBe(2);
});
