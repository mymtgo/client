<?php

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('ships the KPI row props the dashboard cards read', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);

    $this->get(route('decks.show', ['deck' => $deck->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Dashboard')
            ->has('playDrawGames')
            ->has('winrateDelta.delta')
            ->has('winrateDelta.previousRate')
            ->has('winrateDelta.previousTotal')
            ->etc()
        );
});

it('defers the panels that query beyond the eager payload', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);

    $response = $this->get(route('decks.show', ['deck' => $deck->id]));

    $deferred = collect(data_get($response->viewData('page'), 'deferredProps'))->flatten();

    expect($deferred)->toContain('boardingSplit', 'matchupSpread', 'leagueInProgress');
});
