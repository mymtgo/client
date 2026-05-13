<?php

use App\Actions\Pipeline\MetaMessage\ApplyCardPlay;
use App\Enums\MetaMessageKind;
use App\Facades\Mtgo;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function playCardParsed(string $player, int $multiverseId, string $name = 'Plains'): array
{
    return [
        'type' => 3,
        'kind' => MetaMessageKind::PlayCard->value,
        'text' => null,
        'cards' => null,
        'event' => [
            'action' => 'play_card',
            'player' => $player,
            'card' => [
                'name' => $name,
                'multiverse_id' => $multiverseId,
                'instance_id' => 1,
            ],
        ],
    ];
}

function makePlayMatchWithDeckVersion(): MtgoMatch
{
    $version = DeckVersion::factory()->create();

    return MtgoMatch::factory()->create(['deck_version_id' => $version->id]);
}

it('records a play on the (oracle, game, opponent, turn) tuple', function () {
    Card::factory()->create(['mtgo_id' => 12345, 'oracle_id' => 'oracle-plains']);
    $match = makePlayMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 200,
        'turn_count' => 3,
    ]);
    $event = LogEvent::factory()->create(['game_id' => 200]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardPlay)->apply($event, playCardParsed('me', 12345), $context);

    $row = CardGameStat::first();
    expect($row)->not->toBeNull()
        ->and($row->oracle_id)->toBe('oracle-plains')
        ->and($row->opponent)->toBeFalse()
        ->and($row->turn_number)->toBe(3)
        ->and($row->played)->toBe(1);
});

it('increments played on a second event same turn', function () {
    Card::factory()->create(['mtgo_id' => 12345, 'oracle_id' => 'oracle-plains']);
    $match = makePlayMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 200,
        'turn_count' => 3,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    $event1 = LogEvent::factory()->create(['game_id' => 200]);
    $event2 = LogEvent::factory()->create(['game_id' => 200]);

    (new ApplyCardPlay)->apply($event1, playCardParsed('me', 12345), $context);
    (new ApplyCardPlay)->apply($event2, playCardParsed('me', 12345), $context);

    expect(CardGameStat::first()->played)->toBe(2);
});

it('marks opponent=true when player is not local', function () {
    Card::factory()->create(['mtgo_id' => 12345, 'oracle_id' => 'oracle-plains']);
    $match = makePlayMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 200,
        'turn_count' => 3,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardPlay)->apply(
        LogEvent::factory()->create(['game_id' => 200]),
        playCardParsed('opp', 12345),
        $context,
    );

    expect(CardGameStat::first()->opponent)->toBeTrue();
});

it('skips when local username unresolved', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn(null);

    Card::factory()->create(['mtgo_id' => 12345, 'oracle_id' => 'oracle-plains']);
    $match = makePlayMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 200,
        'turn_count' => 1,
    ]);

    (new ApplyCardPlay)->apply(
        LogEvent::factory()->create(['game_id' => 200]),
        playCardParsed('me', 12345),
        new PipelineContext,
    );

    expect(CardGameStat::count())->toBe(0);
});

it('skips when oracle_id is unknown for the multiverse_id', function () {
    $match = makePlayMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 200,
        'turn_count' => 1,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardPlay)->apply(
        LogEvent::factory()->create(['game_id' => 200]),
        playCardParsed('me', 99999),
        $context,
    );

    expect(CardGameStat::count())->toBe(0);
});
