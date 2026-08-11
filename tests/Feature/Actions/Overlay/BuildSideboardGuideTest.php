<?php

use App\Actions\Overlay\BuildSideboardGuide;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
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
