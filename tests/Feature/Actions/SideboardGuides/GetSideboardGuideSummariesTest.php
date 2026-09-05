<?php

use App\Actions\SideboardGuides\GetSideboardGuideSummaries;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A complete match for $version against $archetype with the given outcome and one decided game. */
function summaryMatch(DeckVersion $version, Archetype $archetype, string $token, MatchOutcome $outcome): void
{
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::Complete, 'outcome' => $outcome,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $opponent = Player::create(['username' => 'opp-'.$token]);
    $local = Player::create(['username' => 'me-'.$token]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now(), 'won' => $outcome === MatchOutcome::Win]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 'i-1']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
    ]);
}

it('summarises each guide with card totals, note count and matchup record', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();
    $blink = Archetype::factory()->create(['name' => 'Esper Blink', 'color_identity' => 'WUB']);

    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-a', 'quantity' => 2]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-b', 'quantity' => 1]);
    SideboardGuideCard::factory()->out()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-c', 'quantity' => 3]);
    DeckArchetypeNote::factory()->count(2)->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);

    summaryMatch($version, $blink, 'w1', MatchOutcome::Win);
    summaryMatch($version, $blink, 'w2', MatchOutcome::Win);
    summaryMatch($version, $blink, 'l1', MatchOutcome::Loss);

    $summaries = GetSideboardGuideSummaries::run($deck);

    expect($summaries)->toHaveCount(1);

    $summary = $summaries[0];
    expect($summary->id)->toBe($guide->id);
    expect($summary->archetypeName)->toBe('Esper Blink');
    expect($summary->archetypeColorIdentity)->toBe('WUB');
    expect($summary->cardsIn)->toBe(3);
    expect($summary->cardsOut)->toBe(3);
    expect($summary->notesCount)->toBe(2);
    expect($summary->matches)->toBe(3);
    expect($summary->matchRecord)->toBe('2 - 1');
    expect($summary->matchWinrate)->toBe(67);
    expect($summary->gameWinrate)->toBe(67);
});

it('returns null win rates for an archetype the deck has never faced', function () {
    $deck = Deck::factory()->create();
    $tron = Archetype::factory()->create(['name' => 'Mono Green Tron']);
    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $tron->id]);

    $summary = GetSideboardGuideSummaries::run($deck)[0];

    expect($summary->matches)->toBe(0);
    expect($summary->matchRecord)->toBeNull();
    expect($summary->matchWinrate)->toBeNull();
    expect($summary->gameWinrate)->toBeNull();
    expect($summary->cardsIn)->toBe(0);
    expect($summary->notesCount)->toBe(0);
});

it('orders by archetype name and ignores other decks', function () {
    $deck = Deck::factory()->create();
    $other = Deck::factory()->create();
    $tron = Archetype::factory()->create(['name' => 'Tron']);
    $blink = Archetype::factory()->create(['name' => 'Blink']);

    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $tron->id]);
    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);
    SideboardGuide::factory()->create(['deck_id' => $other->id, 'archetype_id' => $blink->id]);

    $names = collect(GetSideboardGuideSummaries::run($deck))->pluck('archetypeName')->all();

    expect($names)->toBe(['Blink', 'Tron']);
});

it('summarises a single guide', function () {
    $deck = Deck::factory()->create();
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id]);

    expect(GetSideboardGuideSummaries::forGuide($guide)->id)->toBe($guide->id);
});
