<?php

use App\Actions\Limited\ReadRegisteredDeck;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param  list<array<string, mixed>>  $cards */
function registeredDeckEvent(string $matchToken, array $cards, string $loggedAt): LogEvent
{
    $json = json_encode(['MatchToken' => $matchToken, 'MatchID' => 289328482, 'Cards' => $cards, 'ResponseCode' => 1]);

    return LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => $matchToken,
        'raw_text' => "12:37:19 [INF] (BaseClient|Inbound: FlsMatchDeckGetRespMessage) {$json}",
        'logged_at' => $loggedAt,
    ]);
}

it('reads the newest registered deck and treats a missing InSideboard as main deck', function () {
    $match = MtgoMatch::factory()->create(['token' => 'm-read']);

    registeredDeckEvent('m-read', [
        ['CatalogID' => 111, 'Quantity' => 1, 'InSideboard' => false],
    ], '2026-08-22 11:00:00');

    registeredDeckEvent('m-read', [
        ['CatalogID' => 154228, 'Quantity' => 2, 'InSideboard' => false],
        ['CatalogID' => 153896, 'Quantity' => 1, 'InSideboard' => true],
        ['CatalogID' => 153894, 'Quantity' => 6],
    ], '2026-08-22 12:00:00');

    expect(ReadRegisteredDeck::run($match))->toBe([
        ['catalog_id' => 154228, 'quantity' => 2, 'sideboard' => false],
        ['catalog_id' => 153896, 'quantity' => 1, 'sideboard' => true],
        ['catalog_id' => 153894, 'quantity' => 6, 'sideboard' => false],
    ]);
});

it('sums duplicate main-deck rows and drops the sideboard', function () {
    $cards = [
        ['catalog_id' => 154228, 'quantity' => 2, 'sideboard' => false],
        ['catalog_id' => 154228, 'quantity' => 1, 'sideboard' => false],
        ['catalog_id' => 153896, 'quantity' => 3, 'sideboard' => true],
    ];

    expect(ReadRegisteredDeck::mainDeck($cards))->toBe([154228 => 3]);
});

it('returns null when the match has no registered deck event', function () {
    $match = MtgoMatch::factory()->create(['token' => 'm-none']);

    expect(ReadRegisteredDeck::run($match))->toBeNull();
});

it('returns null when the response carries no cards', function () {
    $match = MtgoMatch::factory()->create(['token' => 'm-empty']);
    registeredDeckEvent('m-empty', [], '2026-08-22 12:00:00');

    expect(ReadRegisteredDeck::run($match))->toBeNull();
});
