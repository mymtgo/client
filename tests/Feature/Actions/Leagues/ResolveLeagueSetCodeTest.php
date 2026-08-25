<?php

use App\Actions\Leagues\ResolveLeagueSetCode;
use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('splits repeated set codes of any length', function (string $code, ?string $expected) {
    expect(ResolveLeagueSetCode::fromPlayFormat($code))->toBe($expected);
})->with([
    ['DHOBHOBHOB', 'HOB'],
    ['DM21M21M21', 'M21'],
    ['DLTRLTRLTR', 'LTR'],
    ['HOBx3', 'HOB'],
    ['MSHx3', 'MSH'],
    ['CMODERN', null],
    ['DHOBLTRMSH', null],
    ['', null],
]);

it('sets set code and kind from the match play format', function () {
    $league = League::factory()->create();

    ResolveLeagueSetCode::run($league, 'DHOBHOBHOB');

    expect($league->fresh()->set_code)->toBe('HOB')
        ->and($league->fresh()->kind)->toBe(LeagueKind::Draft);
});

it('falls back to the league panel format when no match format is known', function () {
    $league = League::factory()->create(['event_id' => 11039, 'kind' => LeagueKind::Draft]);
    LogEvent::factory()->create([
        'event_type' => 'league_joined',
        'match_token' => 'tok',
        'match_id' => '11039',
        'raw_text' => "12:00:13 [INF] (UI|Creating GameDetailsView) League\nEventToken=tok\nEventId=11039\nPlayFormatCd=HOBx3\nGameStructureCd= HOBx3",
    ]);

    ResolveLeagueSetCode::run($league);

    expect($league->fresh()->set_code)->toBe('HOB');
});

it('falls back to the picked cards set code', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft]);
    $draft = Draft::factory()->for($league)->create();
    Card::factory()->create(['mtgo_id' => '154228', 'set_code' => 'HOB']);
    Card::factory()->create(['mtgo_id' => '153988', 'set_code' => 'HOB']);
    Card::factory()->create(['mtgo_id' => '999', 'set_code' => 'PLST']);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'picked_catalog_id' => 154228]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 2, 'picked_catalog_id' => 153988]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 3, 'picked_catalog_id' => 999]);

    ResolveLeagueSetCode::run($league);

    expect($league->fresh()->set_code)->toBe('HOB');
});

it('never overwrites an existing set code or downgrades kind', function () {
    $league = League::factory()->create(['set_code' => 'HOB', 'kind' => LeagueKind::Draft]);

    ResolveLeagueSetCode::run($league, 'CMODERN');

    expect($league->fresh()->set_code)->toBe('HOB')
        ->and($league->fresh()->kind)->toBe(LeagueKind::Draft);
});
