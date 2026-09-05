<?php

use App\Actions\Overlay\BuildSideboardGuide;
use App\Enums\MatchState;
use App\Enums\SideboardGuideScope;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Build a deck version whose signature encodes `mtgo_id:quantity:sideboard` rows. */
function guideVersion(Deck $deck, array $rows): DeckVersion
{
    $signature = base64_encode(collect($rows)->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")->implode('|'));

    return DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => $signature,
        'modified_at' => now(),
    ]);
}

/**
 * A postboard game against $archetype with one card_game_stats row per entry in
 * $stats: ['oracle_id' => ['sided_in' => bool, 'sided_out' => bool]].
 */
function guideGame(DeckVersion $version, Archetype $archetype, string $token, bool $won, array $stats): void
{
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::Complete,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $opponent = Player::create(['username' => 'opp-'.$token]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
    ]);

    foreach ($stats as $oracleId => $flags) {
        CardGameStat::create([
            'oracle_id' => $oracleId,
            'game_id' => $game->id,
            'deck_version_id' => $version->id,
            'quantity' => 2,
            'won' => $won,
            'is_postboard' => true,
            'sided_in' => $flags['sided_in'] ?? false,
            'sided_out' => $flags['sided_out'] ?? false,
            'opponent' => false,
        ]);
    }
}

beforeEach(function () {
    Card::create(['mtgo_id' => '201', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment', 'color_identity' => 'W']);
    Card::create(['mtgo_id' => '202', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant', 'color_identity' => 'R']);
    Card::create(['mtgo_id' => '203', 'oracle_id' => 'o-cut', 'name' => 'Cut Down', 'type' => 'Instant', 'color_identity' => 'B']);
    Card::create(['mtgo_id' => '302', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant', 'color_identity' => 'R']);
});

it('reports sided-in W/L and the postboard baseline against the archetype', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // 201 sideboard, 202 maindeck, 203 sideboard.
    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0'], ['203', '2', '1']]);

    guideGame($version, $archetype, 'tok-g2', true, [
        'o-rip' => ['sided_in' => true],
        'o-bolt' => ['sided_out' => true],
    ]);

    guideGame($version, $archetype, 'tok-g3', false, [
        'o-rip' => ['sided_in' => true],
        'o-bolt' => ['sided_out' => true],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype);

    expect($guide->postboardGames)->toBe(2);
    expect($guide->postboardRecord)->toBe('1 - 1');

    $rip = collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip');

    expect($rip->sidedInGames)->toBe(2);
    expect($rip->wins)->toBe(1);
    expect($rip->losses)->toBe(1);
    expect($rip->winrate)->toBe(50);

    // Sideboard cards with no sided-in games still appear, with no record.
    $cut = collect($guide->sidedIn)->firstWhere('oracleId', 'o-cut');

    expect($cut)->not->toBeNull();
    expect($cut->sidedInGames)->toBe(0);
    expect($cut->winrate)->toBeNull();

    // Most-used first.
    expect($guide->sidedIn[0]->oracleId)->toBe('o-rip');

    expect($guide->sidedOut)->toHaveCount(1);
    expect($guide->sidedOut[0]->oracleId)->toBe('o-bolt');
    expect($guide->sidedOut[0]->sidedOutGames)->toBe(2);
});

it('lists cards from the current version while counting games from every version', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    $oldVersion = guideVersion($deck, [['201', '2', '1'], ['203', '2', '1']]);
    // Cut Down (203) is gone from the current list.
    $currentVersion = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0']]);

    guideGame($oldVersion, $archetype, 'tok-old', true, [
        'o-rip' => ['sided_in' => true],
        'o-cut' => ['sided_in' => true],
    ]);

    $guide = BuildSideboardGuide::run($currentVersion, $archetype);

    $oracles = collect($guide->sidedIn)->pluck('oracleId');

    expect($oracles)->toContain('o-rip');
    expect($oracles)->not->toContain('o-cut');

    // The old version's game still counts toward Rest in Peace's record.
    expect(collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip')->sidedInGames)->toBe(1);
});

it('excludes games against other archetypes', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $other = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern']);

    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1']]);

    guideGame($version, $other, 'tok-other', true, ['o-rip' => ['sided_in' => true]]);

    $guide = BuildSideboardGuide::run($version, $archetype);

    expect($guide->postboardGames)->toBe(0);
    expect(collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip')->sidedInGames)->toBe(0);
});

it('names every sideboard card with no postboard history at all', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    $version = guideVersion($deck, [['201', '2', '1'], ['203', '2', '1'], ['202', '4', '0']]);

    // First encounter with this archetype: no games, so no card_game_stats rows
    // at all. The whole sideboard must still be listed by name — that is the
    // spec's headline empty state.
    $guide = BuildSideboardGuide::run($version, $archetype);

    expect($guide->postboardGames)->toBe(0);
    expect($guide->sidedIn)->toHaveCount(2);
    expect(collect($guide->sidedIn)->pluck('name')->all())->toBe(['Cut Down', 'Rest in Peace']);
    expect(collect($guide->sidedIn)->pluck('name'))->not->toContain('Unknown card');

    $rip = collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip');

    expect($rip->colorIdentity)->toBe('W');
    expect($rip->sidedInGames)->toBe(0);
    expect($rip->winrate)->toBeNull();
});

it('lists a card split between the maindeck and the sideboard once', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // Rest in Peace: 3 in the maindeck, 1 in the sideboard. GenerateDeckSignature
    // emits one segment per source entry, so both share oracle_id o-rip.
    $version = guideVersion($deck, [['201', '3', '0'], ['201', '1', '1'], ['202', '4', '0']]);

    guideGame($version, $archetype, 'tok-split-in', true, [
        'o-rip' => ['sided_in' => true],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype);

    $rip = collect($guide->sidedIn)->where('oracleId', 'o-rip');

    expect($rip)->toHaveCount(1);
    // The quantity is the sideboard copies available to bring in, not the
    // maindeck ones.
    expect($rip->first()->quantity)->toBe(1);
    expect($rip->first()->name)->toBe('Rest in Peace');

    // And it stays out of the sided-out list, so it never appears twice.
    expect(collect($guide->sidedOut)->pluck('oracleId'))->not->toContain('o-rip');
});

it('keeps sideboard cards out of the sided-out list', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0']]);

    // A split card: flagged sideboard, but its quantity dropped in this game.
    guideGame($version, $archetype, 'tok-split', true, [
        'o-rip' => ['sided_out' => true],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype);

    expect(collect($guide->sidedOut)->pluck('oracleId'))->not->toContain('o-rip');
});

it('annotates sideboard cards with the community inclusion rate', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1'], ['203', '2', '1'], ['202', '4', '0']]);

    $community = collect([
        'o-rip' => ['sidedIn' => 60, 'sidedOut' => 0, 'games' => 80],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype, $community);

    $rip = collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip');

    expect($rip->communitySidedIn)->toBe(60);
    expect($rip->communityGames)->toBe(80);
    expect($rip->communityRate)->toBe(75);

    // Cut Down is in the sideboard but absent from the API payload: local data
    // stands alone rather than being reported as a 0% community rate.
    $cut = collect($guide->sidedIn)->firstWhere('oracleId', 'o-cut');

    expect($cut->communityRate)->toBeNull();
    expect($cut->communitySidedIn)->toBeNull();
});

it('orders the sided-in list by the community inclusion rate when it is known', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // Neither card has any local history, so the name-ordered fallback would
    // put Cut Down first. The community rates must override that.
    $version = guideVersion($deck, [['201', '2', '1'], ['203', '2', '1']]);

    $community = collect([
        'o-rip' => ['sidedIn' => 72, 'sidedOut' => 0, 'games' => 80],
        'o-cut' => ['sidedIn' => 8, 'sidedOut' => 0, 'games' => 80],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype, $community);

    expect(collect($guide->sidedIn)->pluck('oracleId')->all())->toBe(['o-rip', 'o-cut']);
});

it('lists a maindeck card the community cuts even when you never have', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0']]);

    $community = collect([
        'o-bolt' => ['sidedIn' => 0, 'sidedOut' => 32, 'games' => 80],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype, $community);

    $bolt = collect($guide->sidedOut)->firstWhere('oracleId', 'o-bolt');

    expect($bolt)->not->toBeNull();
    expect($bolt->sidedOutGames)->toBe(0);
    expect($bolt->communitySidedOut)->toBe(32);
    expect($bolt->communityRate)->toBe(40);
});

it('leaves every community field null when no rates are supplied', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1']]);

    $guide = BuildSideboardGuide::run($version, $archetype);

    expect($guide->sidedIn[0]->communityRate)->toBeNull();
    expect($guide->sidedIn[0]->communitySidedIn)->toBeNull();
    expect($guide->sidedIn[0]->communityGames)->toBeNull();
});

it('includes the art crop on sided-in and sided-out cards', function () {
    Card::where('mtgo_id', '201')->update(['art_crop' => 'https://img/rip-art.jpg']);
    Card::where('mtgo_id', '202')->update(['art_crop' => 'https://img/bolt-art.jpg']);

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0']]);

    $community = collect([
        'o-bolt' => ['sidedIn' => 0, 'sidedOut' => 32, 'games' => 80],
    ]);

    $guide = BuildSideboardGuide::run($version, $archetype, $community);

    $rip = collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip');
    $bolt = collect($guide->sidedOut)->firstWhere('oracleId', 'o-bolt');

    expect($rip->artCrop)->toBe('https://img/rip-art.jpg');
    expect($bolt->artCrop)->toBe('https://img/bolt-art.jpg');
});

it('lists only the planned cards in Plan scope with planned quantities and stats', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // 201 sideboard x2, 202 maindeck x4, 203 sideboard x2.
    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0'], ['203', '2', '1']]);

    guideGame($version, $archetype, 'tok-p1', true, ['o-rip' => ['sided_in' => true]]);

    $plan = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $plan->id, 'oracle_id' => 'o-rip', 'quantity' => 1]);
    SideboardGuideCard::factory()->out()->create(['sideboard_guide_id' => $plan->id, 'oracle_id' => 'o-bolt', 'quantity' => 1]);

    $guide = BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Plan, $plan->load('cards'));

    expect($guide->hasPlan)->toBeTrue();

    // o-cut is in the sideboard but not in the plan, so it is not listed.
    expect(collect($guide->sidedIn)->pluck('oracleId')->all())->toBe(['o-rip']);
    expect($guide->sidedIn[0]->quantity)->toBe(1);
    expect($guide->sidedIn[0]->plannedQuantity)->toBe(1);
    expect($guide->sidedIn[0]->sidedInGames)->toBe(1);
    expect($guide->sidedIn[0]->wins)->toBe(1);

    // o-bolt has never been cut in history but the plan says cut it, so it is listed.
    expect(collect($guide->sidedOut)->pluck('oracleId')->all())->toBe(['o-bolt']);
    expect($guide->sidedOut[0]->quantity)->toBe(1);
    expect($guide->sidedOut[0]->plannedQuantity)->toBe(1);
    expect($guide->sidedOut[0]->sidedOutGames)->toBe(0);
});

it('lists every sideboard and maindeck card in Editor scope with planned quantities attached', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0'], ['203', '2', '1']]);

    $plan = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $plan->id, 'oracle_id' => 'o-rip', 'quantity' => 2]);

    $guide = BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Editor, $plan->load('cards'));

    expect(collect($guide->sidedIn)->pluck('oracleId')->sort()->values()->all())->toBe(['o-cut', 'o-rip']);

    $rip = collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip');
    expect($rip->quantity)->toBe(2);
    expect($rip->plannedQuantity)->toBe(2);

    $cut = collect($guide->sidedIn)->firstWhere('oracleId', 'o-cut');
    expect($cut->plannedQuantity)->toBeNull();

    // Zero-history maindeck card is still listed in Editor scope.
    expect(collect($guide->sidedOut)->pluck('oracleId')->all())->toBe(['o-bolt']);
    expect($guide->sidedOut[0]->quantity)->toBe(4);
    expect($guide->sidedOut[0]->plannedQuantity)->toBeNull();
});

it('flags planned cards missing from the current version as stale', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // Rest in Peace (201) is no longer in the deck.
    $version = guideVersion($deck, [['202', '4', '0'], ['203', '2', '1']]);

    $plan = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $plan->id, 'oracle_id' => 'o-rip', 'quantity' => 2]);

    $guide = BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Plan, $plan->load('cards'));

    expect($guide->sidedIn)->toHaveCount(1);
    expect($guide->sidedIn[0]->oracleId)->toBe('o-rip');
    expect($guide->sidedIn[0]->name)->toBe('Rest in Peace');
    expect($guide->sidedIn[0]->stale)->toBeTrue();
    expect($guide->sidedIn[0]->plannedQuantity)->toBe(2);
});

it('reports hasPlan false in History scope and for an empty plan', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1'], ['202', '4', '0']]);

    expect(BuildSideboardGuide::run($version, $archetype)->hasPlan)->toBeFalse();

    $empty = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    expect(BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Editor, $empty->load('cards'))->hasPlan)->toBeFalse();
});

it('rejects Plan and Editor scopes without a plan', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1']]);

    BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Plan);
})->throws(InvalidArgumentException::class);

it('rejects Editor scope without a plan', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $deck = Deck::factory()->create();
    $version = guideVersion($deck, [['201', '2', '1']]);

    BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Editor);
})->throws(InvalidArgumentException::class);

it('marks a planned in-card that moved to the maindeck as stale while still listing it as a maindeck row', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // Rest in Peace (201) used to be in the sideboard; it is now maindeck only.
    $version = guideVersion($deck, [['201', '2', '0'], ['202', '4', '0'], ['203', '2', '1']]);

    $plan = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $plan->id, 'oracle_id' => 'o-rip', 'quantity' => 2]);

    $guide = BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Editor, $plan->load('cards'));

    $staleIn = collect($guide->sidedIn)->firstWhere('oracleId', 'o-rip');
    expect($staleIn)->not->toBeNull();
    expect($staleIn->stale)->toBeTrue();
    expect($staleIn->quantity)->toBe(0);
    expect($staleIn->plannedQuantity)->toBe(2);

    $maindeckRip = collect($guide->sidedOut)->firstWhere('oracleId', 'o-rip');
    expect($maindeckRip)->not->toBeNull();
    expect($maindeckRip->stale)->toBeFalse();
    expect($maindeckRip->quantity)->toBe(2);
    expect($maindeckRip->plannedQuantity)->toBeNull();
});

it('lists a card split across zones in both editor columns with per-zone copies and no stale flag', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    // 3 Bolt main + 1 Bolt board, plus a second Bolt printing in the main.
    $version = guideVersion($deck, [['202', '2', '0'], ['302', '1', '0'], ['202', '1', '1'], ['201', '2', '1']]);

    $plan = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->out()->create(['sideboard_guide_id' => $plan->id, 'oracle_id' => 'o-bolt', 'quantity' => 3]);

    $guide = BuildSideboardGuide::run($version, $archetype, null, SideboardGuideScope::Editor, $plan->load('cards'));

    $boardBolt = collect($guide->sidedIn)->firstWhere('oracleId', 'o-bolt');
    expect($boardBolt)->not->toBeNull();
    expect($boardBolt->quantity)->toBe(1);
    expect($boardBolt->stale)->toBeFalse();

    $mainBolt = collect($guide->sidedOut)->firstWhere('oracleId', 'o-bolt');
    expect($mainBolt)->not->toBeNull();
    expect($mainBolt->quantity)->toBe(3);
    expect($mainBolt->plannedQuantity)->toBe(3);
    expect($mainBolt->stale)->toBeFalse();
});
