<?php

use App\Actions\Drafts\LinkUnlinkedDrafts;
use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * DeckFitsLeaguePool treats a catalog id with no Card row (or a null type) as
 * unresolved and neutral, dropped from both sides of the coverage ratio. So
 * the coverage check only discriminates once the relevant catalog ids are
 * known, non-basic-land cards (mirrors DeckFitsLeaguePoolTest's helper).
 *
 * @param  array<int, int>  $catalogIds
 */
function makeKnownDraftSpells(array $catalogIds, ?string $setCode = null): void
{
    foreach ($catalogIds as $id) {
        Card::factory()->create(['mtgo_id' => (string) $id, 'type' => 'Creature', 'set_code' => $setCode]);
    }
}

/** @param  array<int, int>  $catalogIds */
function draftWithPool(array $catalogIds): Draft
{
    $draft = Draft::factory()->create(['league_id' => null]);
    foreach (array_values($catalogIds) as $i => $id) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $id]);
    }

    return $draft;
}

/** @param  array<int, int>  $catalogIds */
function leagueWithRegisteredDeck(array $catalogIds, array $attributes = []): League
{
    $league = League::factory()->create(array_merge(['kind' => LeagueKind::Draft], $attributes));
    $league->deckSnapshots()->create([
        'source' => 'registered',
        'cards' => array_map(fn ($id) => ['catalog_id' => $id, 'quantity' => 1, 'sideboard' => false], $catalogIds),
        'signature' => 'sig',
        'captured_at' => now(),
    ]);

    return $league;
}

it('adopts a draft-less league whose registered deck is built from the pool', function () {
    makeKnownDraftSpells(range(1000, 1022));

    $league = leagueWithRegisteredDeck(range(1000, 1022), ['event_id' => 11039, 'mtgo_course_id' => null]);
    $draft = draftWithPool(range(1000, 1041));

    LinkUnlinkedDrafts::run();

    expect($draft->fresh()->league_id)->toBe($league->id);
});

it('leaves a draft unlinked when no league deck fits', function () {
    makeKnownDraftSpells(range(5000, 5022));

    $league = leagueWithRegisteredDeck(range(5000, 5022));
    $draft = draftWithPool(range(1000, 1041));

    LinkUnlinkedDrafts::run();

    expect($draft->fresh()->league_id)->toBeNull();
});

it('leaves a draft unlinked when the registered deck is a different draft entirely and pools are unresolved', function () {
    // No Card rows at all: DeckFitsLeaguePool alone would treat every
    // catalog id as unresolved/neutral and return true trivially. The
    // quantity-weighted overlap gate must be the thing that blocks this.
    $league = leagueWithRegisteredDeck(range(5000, 5022));
    $draft = draftWithPool(range(1000, 1041));

    LinkUnlinkedDrafts::run();

    expect($draft->fresh()->league_id)->toBeNull();
});

it('links on unresolved pools when the deck is genuinely built from that pool', function () {
    // Card rows exist as bare stubs (no type yet, as CreateMissingCards
    // leaves them) so DeckFitsLeaguePool's coverage ratio is neutral-true,
    // but the raw quantity-weighted overlap is real and above threshold.
    foreach (range(1000, 1022) as $id) {
        Card::factory()->stub()->create(['mtgo_id' => (string) $id]);
    }

    $league = leagueWithRegisteredDeck(range(1000, 1022));
    $draft = draftWithPool(range(1000, 1041));

    LinkUnlinkedDrafts::run();

    expect($draft->fresh()->league_id)->toBe($league->id);
});

it('cross-matches two unlinked drafts to their correct leagues regardless of iteration order', function () {
    makeKnownDraftSpells(range(1000, 1022));
    makeKnownDraftSpells(range(2000, 2022));

    $leagueA = leagueWithRegisteredDeck(range(1000, 1022));
    $leagueB = leagueWithRegisteredDeck(range(2000, 2022));

    $draftA = draftWithPool(range(1000, 1041));
    $draftB = draftWithPool(range(2000, 2041));

    LinkUnlinkedDrafts::run();

    expect($draftA->fresh()->league_id)->toBe($leagueA->id)
        ->and($draftB->fresh()->league_id)->toBe($leagueB->id);
});

it('blocks a link when the set codes disagree even though the pool overlaps', function () {
    makeKnownDraftSpells(range(1000, 1022), 'HOB');

    $league = leagueWithRegisteredDeck(range(1000, 1022), ['set_code' => 'MSH']);
    $draft = draftWithPool(range(1000, 1041));

    LinkUnlinkedDrafts::run();

    expect($draft->fresh()->league_id)->toBeNull();
});

it('blocks a link when the league started well before the draft', function () {
    // Carbon 3 hands back a signed difference, so a league that started
    // before the draft reads as a negative gap and would sail past a
    // one-sided "> 7 days" filter.
    makeKnownDraftSpells(range(1000, 1022));

    $league = leagueWithRegisteredDeck(range(1000, 1022), ['started_at' => now()->subDays(10)]);
    $draft = draftWithPool(range(1000, 1041));
    $draft->update(['started_at' => now()]);

    LinkUnlinkedDrafts::run();

    expect($draft->fresh()->league_id)->toBeNull();
});
