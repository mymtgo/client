<?php

use App\Actions\Leagues\ResolveLeagueSetCode;
use App\Actions\Matches\AssignLeague;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Match meta as MTGO logs it for a sealed league match: S, the booster
 * count, then the set code. Draft codes start with D, constructed with C.
 *
 * @return array<string, string>
 */
function sealedGameMeta(string $token = 'sealed-league-token'): array
{
    return [
        'League Token' => $token,
        'PlayFormatCd' => 'S6FRA',
        'GameStructureCd' => ' FRAx6',
    ];
}

it('reads sealed format codes as limited', function (string $format, bool $limited) {
    expect(MtgoMatch::isLimitedFormatCode($format))->toBe($limited);
})->with([
    'sealed' => ['S6FRA', true],
    'sealed, two digit boosters' => ['S12FRA', true],
    'draft' => ['DHOBHOBHOB', true],
    'constructed' => ['CMODERN', false],
    'format name starting with S' => ['Standard', false],
    'bare S' => ['S', false],
]);

it('keeps sealed matches out of the not-limited scope', function () {
    MtgoMatch::factory()->create(['format' => 'S6FRA']);
    $modern = MtgoMatch::factory()->create(['format' => 'CMODERN']);

    expect(MtgoMatch::query()->notLimitedFormat()->pluck('id')->all())->toBe([$modern->id]);
});

it('maps format codes to a league kind', function (?string $format, LeagueKind $kind) {
    expect(LeagueKind::fromFormatCode($format))->toBe($kind);
})->with([
    'sealed' => ['S6FRA', LeagueKind::Sealed],
    'draft' => ['DHOB', LeagueKind::Draft],
    'constructed' => ['CMODERN', LeagueKind::Constructed],
    'unknown' => [null, LeagueKind::Constructed],
]);

it('runs sealed leagues for six matches', function () {
    expect(LeagueKind::Sealed->roundCount())->toBe(6);
});

it('mints a sealed league with its set code from a sealed match', function () {
    $match = MtgoMatch::factory()->create(['format' => 'S6FRA']);

    AssignLeague::run($match, sealedGameMeta());

    $league = $match->refresh()->league;

    expect($league->kind)->toBe(LeagueKind::Sealed)
        ->and($league->set_code)->toBe('FRA');
});

it('is idempotent across a second assignment of the same sealed match', function () {
    $match = MtgoMatch::factory()->create(['format' => 'S6FRA']);

    AssignLeague::run($match, sealedGameMeta());
    AssignLeague::run($match->refresh(), sealedGameMeta());

    expect(League::count())->toBe(1)
        ->and(League::first()->kind)->toBe(LeagueKind::Sealed);
});

it('attaches a sixth match to an active sealed league but not a seventh', function () {
    $league = League::factory()->create(['token' => 'sealed-league-token', 'kind' => LeagueKind::Sealed, 'format' => 'S6FRA', 'state' => LeagueState::Active]);
    MtgoMatch::factory()->count(5)->create(['league_id' => $league->id, 'format' => 'S6FRA']);

    $sixth = MtgoMatch::factory()->create(['format' => 'S6FRA']);
    AssignLeague::run($sixth, sealedGameMeta());

    expect($sixth->refresh()->league_id)->toBe($league->id);

    $seventh = MtgoMatch::factory()->create(['format' => 'S6FRA']);
    AssignLeague::run($seventh, sealedGameMeta());

    expect($seventh->refresh()->league_id)->not->toBe($league->id);
});

it('corrects a league minted as constructed once a sealed match arrives', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed, 'set_code' => null]);

    ResolveLeagueSetCode::run($league, 'S6FRA');

    expect($league->refresh()->kind)->toBe(LeagueKind::Sealed)
        ->and($league->set_code)->toBe('FRA');
});

it('reads the set code from a sealed format code', function () {
    expect(ResolveLeagueSetCode::fromPlayFormat('S6FRA'))->toBe('FRA')
        ->and(ResolveLeagueSetCode::fromPlayFormat('FRAx6'))->toBe('FRA');
});

it('re-marks sealed leagues an older version stored as constructed', function () {
    $sealed = League::factory()->create(['kind' => LeagueKind::Constructed, 'format' => 'S6FRA']);
    $modern = League::factory()->create(['kind' => LeagueKind::Constructed, 'format' => 'CMODERN']);

    (require database_path('migrations/2026_10_01_000001_mark_sealed_leagues.php'))->up();

    expect($sealed->refresh()->kind)->toBe(LeagueKind::Sealed)
        ->and($modern->refresh()->kind)->toBe(LeagueKind::Constructed);
});
