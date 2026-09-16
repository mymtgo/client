<?php

use App\Actions\Decks\BuildDeckArchetypeHeader;
use App\Actions\Decks\BuildDeckSidebarOptions;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedMatchup(DeckVersion $version, Archetype $opponent, string $outcome): MtgoMatch
{
    $localPlayer = Player::firstOrCreate(['username' => 'local_player']);
    $opponentPlayer = Player::firstOrCreate(['username' => 'opponent_player']);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'outcome' => $outcome,
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $opponent->id,
        'player_id' => $opponentPlayer->id,
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => $outcome === 'win',
    ]);

    $game->players()->attach($localPlayer->id, ['is_local' => true, 'on_play' => true, 'instance_id' => fake()->randomNumber(6)]);
    $game->players()->attach($opponentPlayer->id, ['is_local' => false, 'on_play' => false, 'instance_id' => fake()->randomNumber(6)]);

    return $match;
}

function headerDeck(?Archetype $archetype, string $format = 'CModern'): array
{
    $deck = Deck::factory()->create(['archetype_id' => $archetype?->id, 'format' => $format]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$deck, $version];
}

it('returns null when no archetype filter is set', function () {
    expect(BuildDeckArchetypeHeader::run('', null, true))->toBeNull();
});

it('returns null for an archetype with no decks in scope', function () {
    $archetype = Archetype::factory()->create();

    expect(BuildDeckArchetypeHeader::run((string) $archetype->id, null, true))->toBeNull();
});

it('reports the same record and deck count as the sidebar row', function () {
    $mine = Archetype::factory()->create(['name' => 'Esper Blink']);
    $opp = Archetype::factory()->create();
    [, $v1] = headerDeck($mine);
    [, $v2] = headerDeck($mine);
    seedMatchup($v1, $opp, 'win');
    seedMatchup($v1, $opp, 'loss');
    seedMatchup($v2, $opp, 'draw');

    $header = BuildDeckArchetypeHeader::run((string) $mine->id, null, true);
    $sidebar = BuildDeckSidebarOptions::archetypeOptions(null, true)->toCollection()->firstWhere('id', $mine->id);

    expect($header->archetype->name)->toBe('Esper Blink');
    expect($header->deckCount)->toBe(2);
    expect($header->record->toArray())->toBe($sidebar->record->toArray());
    expect($header->record->total)->toBe(3);
    expect($header->record->wins)->toBe(1);
});

it('picks best and worst matchups by win rate with a three match floor', function () {
    $mine = Archetype::factory()->create();
    $good = Archetype::factory()->create(['name' => 'Boros Energy']);
    $bad = Archetype::factory()->create(['name' => 'Tron']);
    $thin = Archetype::factory()->create(['name' => 'Thin Sample']);
    [, $version] = headerDeck($mine);

    foreach (['win', 'win', 'win', 'loss'] as $o) {
        seedMatchup($version, $good, $o);
    }   // 75%
    foreach (['win', 'loss', 'loss', 'loss'] as $o) {
        seedMatchup($version, $bad, $o);
    }  // 25%
    foreach (['win', 'win'] as $o) {
        seedMatchup($version, $thin, $o);
    }                   // 100% but only 2

    $header = BuildDeckArchetypeHeader::run((string) $mine->id, null, true);

    expect($header->bestMatchup->name)->toBe('Boros Energy');
    expect($header->bestMatchup->winrate)->toBe(75);
    expect($header->bestMatchup->matches)->toBe(4);
    expect($header->worstMatchup->name)->toBe('Tron');
    expect($header->worstMatchup->winrate)->toBe(25);
});

it('breaks win rate ties by match count', function () {
    $mine = Archetype::factory()->create();
    $a = Archetype::factory()->create(['name' => 'A']);
    $b = Archetype::factory()->create(['name' => 'B']);
    [, $version] = headerDeck($mine);

    foreach (['win', 'win', 'win'] as $o) {
        seedMatchup($version, $a, $o);
    }             // 100%, 3
    foreach (['win', 'win', 'win', 'win'] as $o) {
        seedMatchup($version, $b, $o);
    }      // 100%, 4

    $header = BuildDeckArchetypeHeader::run((string) $mine->id, null, true);

    expect($header->bestMatchup->name)->toBe('B');
});

it('returns null matchups when fewer than two opponents qualify', function () {
    $mine = Archetype::factory()->create();
    $only = Archetype::factory()->create();
    [, $version] = headerDeck($mine);
    foreach (['win', 'win', 'loss'] as $o) {
        seedMatchup($version, $only, $o);
    }

    $header = BuildDeckArchetypeHeader::run((string) $mine->id, null, true);

    expect($header->bestMatchup)->toBeNull();
    expect($header->worstMatchup)->toBeNull();
});

it('builds an unclassified header with record and no matchups', function () {
    $opp = Archetype::factory()->create();
    [, $version] = headerDeck(null);
    headerDeck(null);
    foreach (['win', 'win', 'win', 'loss'] as $o) {
        seedMatchup($version, $opp, $o);
    }

    $header = BuildDeckArchetypeHeader::run('none', null, true);

    expect($header->archetype)->toBeNull();
    expect($header->deckCount)->toBe(2);
    expect($header->record->total)->toBe(4);
    expect($header->bestMatchup)->toBeNull();
    expect($header->worstMatchup)->toBeNull();
});

it('respects the format and archived scope', function () {
    $mine = Archetype::factory()->create();
    headerDeck($mine, 'CModern');
    headerDeck($mine, 'CLegacy');
    [$deleted] = headerDeck($mine, 'CModern');
    $deleted->delete();

    expect(BuildDeckArchetypeHeader::run((string) $mine->id, 'CModern', true)->deckCount)->toBe(1);
    expect(BuildDeckArchetypeHeader::run((string) $mine->id, 'CModern', false)->deckCount)->toBe(2);
    expect(BuildDeckArchetypeHeader::run((string) $mine->id, null, true)->deckCount)->toBe(2);
});
