<?php

use App\Sidecar\DeckSnapshot;
use App\Sidecar\LeagueSnapshot;
use Tests\Helpers\SidecarSnapshotFactory;

it('parses a full league snapshot', function () {
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league([
        'match_number' => 3, 'wins' => 1, 'losses' => 1,
        'game_history' => [['match_id' => 290093991], ['match_id' => 290093991], ['match_id' => 290094701]],
    ]));

    expect($s->eventId)->toBe(10983)
        ->and($s->token)->toBe('league-token-123')
        ->and($s->isNewRun())->toBeFalse()
        ->and($s->priorMatchCount())->toBe(2)
        ->and($s->historyMatchIds)->toBe(['290093991', '290094701']);
});

it('treats match 1 as a new run', function () {
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league());

    expect($s->isNewRun())->toBeTrue()->and($s->priorMatchCount())->toBe(0);
});

it('cannot tell run position without a match number', function () {
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league(['match_number' => null]));

    expect($s->isNewRun())->toBeNull()->and($s->priorMatchCount())->toBeNull();
});

it('keeps an unreadable game history as null, never empty', function () {
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league(['game_history' => null]));

    expect($s->historyMatchIds)->toBeNull();
});

it('skips history rows without a match id', function () {
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league(['game_history' => [['match_id' => null], ['game_id' => 1], ['match_id' => 5]]]));

    expect($s->historyMatchIds)->toBe(['5']);
});

it('returns null for a missing league', function () {
    expect(LeagueSnapshot::fromArray(null))->toBeNull();
});

it('reads run completion from the ended counters', function (array $overrides, bool $finished) {
    expect(LeagueSnapshot::fromArray(SidecarSnapshotFactory::league($overrides))->showsRunFinished())->toBe($finished);
})->with([
    'none remaining' => [['matches_remaining' => 0, 'match_number' => 5], true],
    'last match number' => [['matches_remaining' => 1, 'match_number' => 5], true],
    'mid run' => [['matches_remaining' => 3, 'match_number' => 2], false],
    'unknown' => [['matches_remaining' => null, 'match_number' => null], false],
]);

it('maps deck items to signature rows', function () {
    $d = DeckSnapshot::fromArray(SidecarSnapshotFactory::deck());

    expect($d->netDeckId)->toBe(12345)
        ->and($d->items->all())->toBe([
            ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
            ['mtgo_id' => 1003, 'quantity' => 2, 'sideboard' => 'true'],
        ]);
});

it('drops the whole item list when one row is malformed', function () {
    $d = DeckSnapshot::fromArray(SidecarSnapshotFactory::deck(['items' => [['catalog_id' => 1001], ['catalog_id' => 1003, 'quantity' => 2, 'sideboard' => true]]]));

    expect($d->items)->toBeNull();
});

it('returns null for non-array payloads', function () {
    expect(LeagueSnapshot::fromArray('garbage'))->toBeNull()
        ->and(DeckSnapshot::fromArray(42))->toBeNull();
});
