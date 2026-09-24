<?php

use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('lists the match games in play order so the replay can offer the next one', function () {
    $match = MtgoMatch::factory()->create(['state' => 'complete']);

    $third = Game::factory()->for($match, 'match')->create(['won' => true, 'started_at' => now()->subMinutes(10)]);
    $first = Game::factory()->for($match, 'match')->create(['won' => true, 'started_at' => now()->subMinutes(40)]);
    $second = Game::factory()->for($match, 'match')->create(['won' => false, 'started_at' => now()->subMinutes(25)]);

    Game::factory()->for(MtgoMatch::factory(), 'match')->create(['started_at' => now()->subMinutes(30)]);

    $this->get(route('games.show', $second->id))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('games/Show')
            ->where('matchGames', [
                ['id' => $first->id, 'number' => 1, 'won' => true],
                ['id' => $second->id, 'number' => 2, 'won' => false],
                ['id' => $third->id, 'number' => 3, 'won' => true],
            ]));
});

/**
 * A two-game match between local.player (seat 1) and Opp_Name (seat 2):
 * game 1 starts with Duress and Abrade in your sideboard, game 2 with two
 * Duress and a Thoughtseize. The recorded deck always holds the sideboard;
 * the frames only do when `$inFrames` is set, as log-built timelines do,
 * and never do otherwise, as sidecar ones.
 *
 * @return list<Game>
 */
function sideboardedMatch(bool $inFrames = false, bool $inDeck = true): array
{
    $match = MtgoMatch::factory()->create(['state' => 'complete']);
    $local = Player::factory()->create(['username' => 'local.player']);
    $opponent = Player::factory()->create(['username' => 'Opp_Name']);
    $players = [['Id' => 1, 'Name' => 'local.player'], ['Id' => 2, 'Name' => 'Opp_Name']];
    $catalog = ['Duress' => 101, 'Abrade' => 102, 'Thoughtseize' => 103, 'Ragavan' => 104];

    foreach ($catalog as $name => $catalogId) {
        Card::factory()->create(['mtgo_id' => $catalogId, 'name' => $name, 'type' => 'Sorcery']);
    }

    $games = [];

    foreach ([['Duress' => 1, 'Abrade' => 1], ['Duress' => 2, 'Thoughtseize' => 1]] as $index => $sideboard) {
        $deck = [['mtgo_id' => $catalog['Ragavan'], 'quantity' => 4, 'sideboard' => false]];
        $cards = [['Id' => 50, 'CatalogID' => 900, 'Zone' => 'Sideboard', 'Owner' => 2]];

        foreach ($sideboard as $name => $quantity) {
            $deck[] = ['mtgo_id' => $catalog[$name], 'quantity' => $quantity, 'sideboard' => true];

            foreach (range(1, $quantity) as $copy) {
                $cards[] = ['Id' => count($cards) + $index * 10, 'CatalogID' => $catalog[$name], 'Zone' => 'Sideboard', 'Owner' => 1];
            }
        }

        $game = Game::factory()->for($match, 'match')->create(['started_at' => now()->subMinutes(40 - $index * 20)]);
        $game->players()->attach($local->id, ['instance_id' => 1, 'is_local' => true, 'on_play' => true, 'deck_json' => $inDeck ? $deck : null]);
        $game->players()->attach($opponent->id, ['instance_id' => 2, 'is_local' => false, 'on_play' => false]);

        GameTimeline::create(['game_id' => $game->id, 'timestamp' => '2026-09-20 18:00:00', 'content' => ['Players' => $players, 'Cards' => []]]);
        GameTimeline::create(['game_id' => $game->id, 'timestamp' => '2026-09-20 18:00:01', 'content' => ['Players' => $players, 'Cards' => $inFrames ? $cards : []]]);

        $games[] = $game;
    }

    return $games;
}

/** @param  list<array<string, mixed>>  $entries */
function sideboardNames(array $entries): array
{
    return collect($entries)->map(fn (array $entry) => "{$entry['quantity']} {$entry['name']}")->sort()->values()->all();
}

it('gives a game your recorded sideboard, even when its frames hold none', function () {
    [, $second] = sideboardedMatch();

    $this->get(route('games.show', $second->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sideboard', fn ($entries) => sideboardNames($entries->all()) === ['1 Thoughtseize', '2 Duress'])
            ->where('previousSideboard.game', 1)
            ->where('previousSideboard.sideboard', fn ($entries) => sideboardNames($entries->all()) === ['1 Abrade', '1 Duress']));
});

it('falls back to the frames when no deck was recorded', function () {
    [, $second] = sideboardedMatch(inFrames: true, inDeck: false);

    $this->get(route('games.show', $second->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sideboard', fn ($entries) => sideboardNames($entries->all()) === ['1 Thoughtseize', '2 Duress'])
            ->where('previousSideboard.game', 1)
            ->where('previousSideboard.sideboard', fn ($entries) => sideboardNames($entries->all()) === ['1 Abrade', '1 Duress']));
});

it('gives the first game no previous sideboard', function () {
    [$first] = sideboardedMatch();

    $this->get(route('games.show', $first->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('previousSideboard', null));
});
