<?php

use App\Actions\Pipeline\MetaMessage\ApplyDeckList;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function makeDeckListGame(): Game
{
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);

    $local = Player::factory()->create(['username' => 'localguy']);
    $opp = Player::factory()->create(['username' => 'oppguy']);

    $game->players()->attach($local->id, ['is_local' => true, 'instance_id' => 1]);
    $game->players()->attach($opp->id, ['is_local' => false, 'instance_id' => 2]);

    return $game;
}

function deckListParsed(array $cards): array
{
    return [
        'type' => 1,
        'kind' => MetaMessageKind::DeckList->value,
        'text' => null,
        'cards' => $cards,
        'event' => null,
    ];
}

it('writes the deck_json snapshot on the local players pivot row', function () {
    $game = makeDeckListGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyDeckList)->apply($event, deckListParsed([111, 222, 333]), new PipelineContext);

    $local = $game->players()->wherePivot('is_local', true)->first();
    expect($local->pivot->deck_json)->toBe([111, 222, 333]);

    $opp = $game->players()->wherePivot('is_local', false)->first();
    expect($opp->pivot->deck_json)->toBeNull();
});

it('is idempotent', function () {
    $game = makeDeckListGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyDeckList)->apply($event, deckListParsed([1, 2, 3]), new PipelineContext);
    (new ApplyDeckList)->apply($event, deckListParsed([1, 2, 3]), new PipelineContext);

    $local = $game->players()->wherePivot('is_local', true)->first();
    expect($local->pivot->deck_json)->toBe([1, 2, 3]);
});

it('skips when no local player is attached', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyDeckList)->apply($event, deckListParsed([1]), new PipelineContext);

    expect($game->players()->count())->toBe(0);
});
