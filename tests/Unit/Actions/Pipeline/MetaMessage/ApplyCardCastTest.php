<?php

use App\Actions\Pipeline\MetaMessage\ApplyCardCast;
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

function castCardParsed(string $player, int $multiverseId, string $name = 'Ichor'): array
{
    return [
        'type' => 3,
        'kind' => MetaMessageKind::CastCard->value,
        'text' => null,
        'cards' => null,
        'event' => [
            'action' => 'cast_card',
            'player' => $player,
            'card' => [
                'name' => $name,
                'multiverse_id' => $multiverseId,
                'instance_id' => 1,
            ],
        ],
    ];
}

function makeMatchWithDeckVersion(): MtgoMatch
{
    $version = DeckVersion::factory()->create();

    return MtgoMatch::factory()->create(['deck_version_id' => $version->id]);
}

it('records a cast on the (oracle, game, opponent, turn) tuple', function () {
    Card::factory()->create(['mtgo_id' => 78678, 'oracle_id' => 'oracle-ichor']);
    $match = makeMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 100,
        'turn_count' => 2,
    ]);
    $event = LogEvent::factory()->create(['game_id' => 100]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardCast)->apply($event, castCardParsed('me', 78678), $context);

    $row = CardGameStat::first();
    expect($row)->not->toBeNull()
        ->and($row->oracle_id)->toBe('oracle-ichor')
        ->and($row->opponent)->toBeFalse()
        ->and($row->turn_number)->toBe(2)
        ->and($row->cast)->toBe(1);
});

it('increments cast on a second event same turn', function () {
    // Two separate LogEvents arriving for the same player/card/turn each
    // represent a distinct cast — increment, do not deduplicate by parsed payload.
    Card::factory()->create(['mtgo_id' => 78678, 'oracle_id' => 'oracle-ichor']);
    $match = makeMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 100,
        'turn_count' => 2,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    $event1 = LogEvent::factory()->create(['game_id' => 100]);
    $event2 = LogEvent::factory()->create(['game_id' => 100]);

    (new ApplyCardCast)->apply($event1, castCardParsed('me', 78678), $context);
    (new ApplyCardCast)->apply($event2, castCardParsed('me', 78678), $context);

    expect(CardGameStat::first()->cast)->toBe(2);
});

it('marks opponent=true when caster is not local', function () {
    Card::factory()->create(['mtgo_id' => 78678, 'oracle_id' => 'oracle-ichor']);
    $match = makeMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 100,
        'turn_count' => 2,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardCast)->apply(
        LogEvent::factory()->create(['game_id' => 100]),
        castCardParsed('opp', 78678),
        $context,
    );

    expect(CardGameStat::first()->opponent)->toBeTrue();
});

it('skips when local username unresolved', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn(null);

    Card::factory()->create(['mtgo_id' => 78678, 'oracle_id' => 'oracle-ichor']);
    $match = makeMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 100,
        'turn_count' => 1,
    ]);

    (new ApplyCardCast)->apply(
        LogEvent::factory()->create(['game_id' => 100]),
        castCardParsed('me', 78678),
        new PipelineContext,
    );

    expect(CardGameStat::count())->toBe(0);
});

it('skips when oracle_id is unknown for the multiverse_id', function () {
    // No Card row for multiverse 99999 — handler returns without writing.
    $match = makeMatchWithDeckVersion();
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 100,
        'turn_count' => 1,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardCast)->apply(
        LogEvent::factory()->create(['game_id' => 100]),
        castCardParsed('me', 99999),
        $context,
    );

    expect(CardGameStat::count())->toBe(0);
});

it('skips when match has no deck_version_id', function () {
    Card::factory()->create(['mtgo_id' => 78678, 'oracle_id' => 'oracle-ichor']);
    $match = MtgoMatch::factory()->create(['deck_version_id' => null]);
    Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 100,
        'turn_count' => 1,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('me');

    (new ApplyCardCast)->apply(
        LogEvent::factory()->create(['game_id' => 100]),
        castCardParsed('me', 78678),
        $context,
    );

    expect(CardGameStat::count())->toBe(0);
});
