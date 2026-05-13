<?php

use App\Actions\Pipeline\MetaMessage\ApplyMulligan;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function mulliganParsed(string $player, int $newHandSize): array
{
    return [
        'type' => 2,
        'kind' => MetaMessageKind::Mulligan->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'mulligan', 'player' => $player, 'value' => $newHandSize],
    ];
}

function makeMulliganGame(): array
{
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $alice = Player::factory()->create(['username' => 'alice']);
    $game->players()->attach($alice->id, ['is_local' => true, 'instance_id' => 1]);

    return [$game, $alice];
}

it('writes mulligan_count derived from new hand size', function () {
    [$game, $alice] = makeMulliganGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyMulligan)->apply($event, mulliganParsed('alice', 6), new PipelineContext);

    $pivot = $game->players()->where('player_id', $alice->id)->first()->pivot;
    expect($pivot->mulligan_count)->toBe(1);
    expect($pivot->starting_hand_size)->toBe(6);
});

it('derives starting_hand_size correctly from arbitrary mulligan values', function () {
    [$game, $alice] = makeMulliganGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyMulligan)->apply($event, mulliganParsed('alice', 4), new PipelineContext);

    $pivot = $game->players()->where('player_id', $alice->id)->first()->pivot;
    expect($pivot->mulligan_count)->toBe(3);
    expect($pivot->starting_hand_size)->toBe(4);
});

it('is idempotent', function () {
    [$game, $alice] = makeMulliganGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyMulligan)->apply($event, mulliganParsed('alice', 5), new PipelineContext);
    (new ApplyMulligan)->apply($event, mulliganParsed('alice', 5), new PipelineContext);

    $pivot = $game->players()->where('player_id', $alice->id)->first()->pivot;
    expect($pivot->mulligan_count)->toBe(2);
    expect($pivot->starting_hand_size)->toBe(5);
});
