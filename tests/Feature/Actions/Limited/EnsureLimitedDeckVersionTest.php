<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Actions\Limited\RecordRegisteredDeckSnapshot;
use App\Enums\LeagueKind;
use App\Models\Deck;
use App\Models\Draft;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function registeredDeckLine(string $matchToken, int $matchId, array $cards): string
{
    $json = json_encode(['MatchToken' => $matchToken, 'MatchID' => $matchId, 'Cards' => array_map(fn ($c) => [
        'CatalogID' => $c[0], 'Annotation' => 0, 'PermissionTypeCode' => 0, 'Quantity' => $c[1], 'InSideboard' => $c[2],
    ], $cards), 'ResponseCode' => 1]);

    return "12:37:19 [INF] (BaseClient|Inbound: FlsMatchDeckGetRespMessage) {$json}";
}

it('records a registered snapshot from the match deck response', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft]);
    $match = MtgoMatch::factory()->create(['token' => 'm-1', 'mtgo_id' => '289328482', 'league_id' => $league->id]);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-1',
        'match_id' => '289328482',
        'raw_text' => registeredDeckLine('m-1', 289328482, [[153896, 1, true], [154228, 2, false], [153894, 6, false]]),
    ]);

    $snapshot = RecordRegisteredDeckSnapshot::run($match);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->source)->toBe('registered')
        ->and($snapshot->cards)->toHaveCount(3)
        ->and($snapshot->cards[1])->toBe(['catalog_id' => 154228, 'quantity' => 2, 'sideboard' => false]);

    RecordRegisteredDeckSnapshot::run($match);
    expect($league->deckSnapshots()->count())->toBe(1);
});

it('returns null when the match has no registered deck event', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft]);
    $match = MtgoMatch::factory()->create(['token' => 'm-2', 'league_id' => $league->id]);

    expect(RecordRegisteredDeckSnapshot::run($match))->toBeNull();
});

it('creates one Limited deck per league and one version per distinct signature', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => '2026-08-22 11:12:00']);
    Draft::factory()->for($league)->create(['draft_token' => '791bacca-caea-4d88-b6c7-3bc067d412c2']);

    $first = $league->deckSnapshots()->create(['source' => 'registered', 'cards' => [['catalog_id' => 154228, 'quantity' => 1, 'sideboard' => false]], 'signature' => 'a', 'captured_at' => now()]);
    $second = $league->deckSnapshots()->create(['source' => 'registered', 'match_id' => MtgoMatch::factory()->create()->id, 'cards' => [['catalog_id' => 154228, 'quantity' => 2, 'sideboard' => false]], 'signature' => 'b', 'captured_at' => now()]);

    $v1 = EnsureLimitedDeckVersion::run($league, $first);
    $v1Again = EnsureLimitedDeckVersion::run($league, $first);
    $v2 = EnsureLimitedDeckVersion::run($league, $second);

    $deck = Deck::where('mtgo_id', 'limited:791bacca-caea-4d88-b6c7-3bc067d412c2')->firstOrFail();

    expect($deck->format)->toBe('Limited')
        ->and($deck->name)->toBe('HOB Draft 22 Aug 2026')
        ->and($v1->id)->toBe($v1Again->id)
        ->and($v2->id)->not->toBe($v1->id)
        ->and($deck->versions()->count())->toBe(2)
        ->and($league->fresh()->deck_version_id)->toBe($v2->id)
        ->and($v1->signature)->toBe(GenerateDeckSignature::run(collect([['mtgo_id' => 154228, 'quantity' => 1, 'sideboard' => 'false']])));
});
