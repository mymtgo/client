<?php

use App\Actions\Leagues\GetActiveLeague;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when no active league exists', function () {
    expect(GetActiveLeague::run())->toBeNull();
});

it('returns deckName from deckVersion when present', function () {
    $deck = Deck::factory()->create(['name' => 'Mono Red']);
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
    ]);
    $league = League::factory()->create(['deck_version_id' => $version->id]);

    MtgoMatch::factory()->won()->create([
        'league_id' => $league->id,
        'deck_version_id' => $version->id,
        'started_at' => now(),
    ]);

    $result = GetActiveLeague::run();

    expect($result)->not->toBeNull()
        ->and($result['deckName'])->toBe('Mono Red');
});

it('does not crash when league has no deckVersion', function () {
    $deck = Deck::factory()->create(['name' => 'UWx']);
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
    ]);
    $league = League::factory()->create(['deck_version_id' => null]);

    MtgoMatch::factory()->won()->create([
        'league_id' => $league->id,
        'deck_version_id' => $version->id,
        'started_at' => now(),
    ]);

    expect(fn () => GetActiveLeague::run())->not->toThrow(Error::class);
});
