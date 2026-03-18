<?php

use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Index')
        ->has('activeLeague')
        ->where('activeLeague.deckName', 'Jund Snow')
        ->where('activeLeague.versionLabel', 'v2')
    );
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

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Index')
        ->where('activeLeague.deckName', 'Jeskai Control')
        ->where('activeLeague.wins', 1)
        ->where('activeLeague.losses', 0)
    );
});
