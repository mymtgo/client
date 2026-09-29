<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\Widgets\KpiStripWidget;
use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, mixed>|null */
function dashboardActiveLeague(): ?array
{
    return (new KpiStripWidget)->resolve([], DashboardScope::fromTimeframe('alltime'))['activeLeague'];
}

it('shows deck name and version label from league relationship', function () {
    $deck = Deck::factory()->create(['name' => 'Jund Snow']);
    $version1 = DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => now()->subDays(5)]);
    $version2 = DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => now()]);

    $league = League::factory()->create([
        'deck_version_id' => $version2->id,
        'state' => LeagueState::Active,
    ]);

    MtgoMatch::factory()->won()->create([
        'league_id' => $league->id,
        'deck_version_id' => $version2->id,
        'started_at' => now(),
    ]);

    $league = dashboardActiveLeague();

    expect($league['deckName'])->toBe('Jund Snow')
        ->and($league['versionLabel'])->toBe('v2');
});

it('shows separate league runs for same token with different decks', function () {
    $deck1 = Deck::factory()->create(['name' => 'Jund Snow']);
    $deck2 = Deck::factory()->create(['name' => 'Jeskai Control']);
    $version1 = DeckVersion::factory()->create(['deck_id' => $deck1->id]);
    $version2 = DeckVersion::factory()->create(['deck_id' => $deck2->id]);

    League::factory()->partial()->create([
        'token' => 'same-token',
        'deck_version_id' => $version1->id,
        'started_at' => now()->subDay(),
    ]);
    $league2 = League::factory()->create([
        'token' => 'same-token',
        'deck_version_id' => $version2->id,
        'started_at' => now(),
    ]);

    MtgoMatch::factory()->won()->create([
        'league_id' => $league2->id,
        'deck_version_id' => $version2->id,
        'started_at' => now(),
    ]);

    $league = dashboardActiveLeague();

    expect($league['deckName'])->toBe('Jeskai Control')
        ->and($league['wins'])->toBe(1)
        ->and($league['losses'])->toBe(0);
});
