<?php

use App\Actions\Limited\Analytics\ComputeCrossDraftCardStats;
use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hobLeagueWithDraft(int $seatCount = 8): array
{
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_count' => $seatCount]);

    return [$league, $draft];
}

it('aggregates taken, passed and wheeled per oracle across other drafts of the set', function () {
    // Two printings of Harper share an oracle id.
    Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'harper', 'set_code' => 'HOB', 'name' => 'Harper']);
    Card::factory()->create(['mtgo_id' => '11', 'oracle_id' => 'harper', 'set_code' => 'HOB', 'name' => 'Harper']);
    Card::factory()->create(['mtgo_id' => '2', 'oracle_id' => 'bard', 'set_code' => 'HOB', 'name' => 'Bard']);

    [$current, $currentDraft] = hobLeagueWithDraft();
    DraftPick::factory()->create(['draft_id' => $currentDraft->id, 'ordinal' => 1, 'cards_available' => [1, 2], 'picked_catalog_id' => 2]);

    [$otherA, $draftA] = hobLeagueWithDraft();
    DraftPick::factory()->create(['draft_id' => $draftA->id, 'ordinal' => 1, 'pack_id' => 500, 'cards_available' => [1, 2, 3], 'picked_catalog_id' => 2]);
    DraftPick::factory()->create(['draft_id' => $draftA->id, 'ordinal' => 9, 'pack_id' => 500, 'cards_available' => [1], 'picked_catalog_id' => 1]);
    LimitedDeckSnapshot::create(['league_id' => $otherA->id, 'source' => 'registered', 'signature' => 's', 'captured_at' => now(), 'cards' => [['catalog_id' => 2, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 1, 'quantity' => 1, 'sideboard' => true]]]);

    [, $draftB] = hobLeagueWithDraft();
    DraftPick::factory()->create(['draft_id' => $draftB->id, 'ordinal' => 4, 'cards_available' => [11, 2], 'picked_catalog_id' => 11]);

    $mshLeague = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'MSH', 'started_at' => now()]);
    $mshDraft = Draft::factory()->finished()->create(['league_id' => $mshLeague->id]);
    DraftPick::factory()->create(['draft_id' => $mshDraft->id, 'ordinal' => 1, 'cards_available' => [2], 'picked_catalog_id' => 2]);

    $stats = ComputeCrossDraftCardStats::run($current);

    expect($stats['harper'])->toMatchArray(['drafts' => 2, 'timesTaken' => 2, 'avgOrdinal' => 6.5, 'timesPassed' => 1, 'timesWheeled' => 1, 'madeDeck' => 0])
        ->and($stats['bard'])->toMatchArray(['drafts' => 2, 'timesTaken' => 1, 'avgOrdinal' => 1.0, 'timesPassed' => 1, 'timesWheeled' => 0, 'madeDeck' => 1]);
});

it('returns an empty array when the league has no set code', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => null]);

    expect(ComputeCrossDraftCardStats::run($league))->toBe([]);
});

it('emits a complete row for a pick whose card is missing from its own cards_available', function () {
    Card::factory()->create(['mtgo_id' => '7', 'oracle_id' => 'ghost', 'set_code' => 'HOB', 'name' => 'Ghost']);

    [$current] = hobLeagueWithDraft();

    // Processed first: ApplyDraftEvent::committed() can commit a pick whose
    // card never appeared in the pack it recorded, so the seen/wheel pass
    // never registers this oracle.
    [, $draftA] = hobLeagueWithDraft();
    DraftPick::factory()->create(['draft_id' => $draftA->id, 'ordinal' => 3, 'cards_available' => [], 'picked_catalog_id' => 7]);

    [, $draftB] = hobLeagueWithDraft();
    DraftPick::factory()->create(['draft_id' => $draftB->id, 'ordinal' => 5, 'cards_available' => [7], 'picked_catalog_id' => 7]);

    $stats = ComputeCrossDraftCardStats::run($current);

    expect($stats['ghost'])->toHaveKeys(['oracleId', 'drafts', 'timesTaken', 'timesPassed', 'timesWheeled', 'madeDeck', 'avgOrdinal'])
        ->and($stats['ghost'])->toMatchArray([
            'oracleId' => 'ghost',
            'timesTaken' => 1,
            'timesPassed' => 0,
            'timesWheeled' => 0,
            'madeDeck' => 0,
            'avgOrdinal' => 4.0,
        ]);
});

it('skips registered snapshot rows that carry no catalog id', function () {
    Card::factory()->create(['mtgo_id' => '2', 'oracle_id' => 'bard', 'set_code' => 'HOB', 'name' => 'Bard']);

    [$current] = hobLeagueWithDraft();
    [$other, $otherDraft] = hobLeagueWithDraft();
    DraftPick::factory()->create(['draft_id' => $otherDraft->id, 'ordinal' => 1, 'cards_available' => [2], 'picked_catalog_id' => 2]);
    LimitedDeckSnapshot::create(['league_id' => $other->id, 'source' => 'registered', 'signature' => 's', 'captured_at' => now(), 'cards' => [
        ['catalog_id' => 2, 'quantity' => 1, 'sideboard' => false],
        ['quantity' => 1, 'sideboard' => false],
    ]]);

    $stats = ComputeCrossDraftCardStats::run($current);

    expect($stats)->toHaveCount(1)->and($stats['bard']['madeDeck'])->toBe(1);
});
