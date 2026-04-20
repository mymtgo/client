<?php

use App\Actions\Leagues\FormatLeagueRuns;
use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not blow up when the league\'s deck has been soft-deleted', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'modified_at' => now(),
    ]);

    $league = League::factory()->create([
        'deck_version_id' => $version->id,
        'state' => LeagueState::Complete,
    ]);

    MtgoMatch::factory()->won()->create([
        'league_id' => $league->id,
        'deck_version_id' => $version->id,
        'started_at' => now(),
    ]);

    $deck->delete(); // soft delete — DeckVersion::deck() returns null

    $leagues = League::query()
        ->with(['deckVersion.deck.cover'])
        ->where('id', $league->id)
        ->get();

    $runs = FormatLeagueRuns::run($leagues);

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['versionLabel'])->toBeNull();
});
