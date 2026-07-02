<?php

use App\Actions\Compile\CompileMatch;
use App\Actions\Logs\IngestLogInstance;
use App\Enums\MatchOutcome;
use App\Facades\Mtgo;
use App\Models\AppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const COMPILE_FIXTURE_TOKEN = 'f5e33a1f-c2e7-4678-b30d-309b63f17a40';

function compileFixtureSetup(): void
{
    $path = sys_get_temp_dir().'/compile-match-fixture.log';
    copy(base_path('tests/fixtures/mtgo_league_join_drop.log'), $path);
    IngestLogInstance::run($path);

    AppAccount::create([
        'user_id' => 1,
        'mtgo_player_id' => 147160,
        'mtgo_username' => 'anticloser',
        'active' => true,
    ]);
    Mtgo::setUsername('anticloser');
}

it('compiles the fixture match to the full {match}.json contract', function () {
    compileFixtureSetup();

    $dto = app(CompileMatch::class)->run(COMPILE_FIXTURE_TOKEN);
    $json = $dto->toArray();

    expect($json['schema_version'])->toBe(1);
    expect($json['source'])->toBe('mtgo');
    expect($json['client_version'])->toBe(config('nativephp.version'));
    expect($json['match_key'])->toBe($json['match']['token']);
    expect($json['imported'])->toBeFalse();
    expect($json['compiled_at'])->not->toBeNull();
    expect($json['mtgo_username'])->toBe('anticloser');
    expect($json['mtgo_player_id'])->toBe(147160);
    expect($json['match']['games'])->toHaveCount(2);
});

it('bakes the resolved outcome into the match', function () {
    compileFixtureSetup();

    $dto = app(CompileMatch::class)->run(COMPILE_FIXTURE_TOKEN);

    expect($dto->match->outcome)->not->toBe(MatchOutcome::Unknown);
    expect($dto->match->outcome_source->value)->toBe('resolved');
});

it('returns null for a token that is not ours', function () {
    compileFixtureSetup();

    expect(app(CompileMatch::class)->run('never-seen'))->toBeNull();
});

it('returns null and holds when identity is unresolved', function () {
    $path = sys_get_temp_dir().'/compile-match-fixture.log';
    copy(base_path('tests/fixtures/mtgo_league_join_drop.log'), $path);
    IngestLogInstance::run($path);
    // No AppAccount binding → identity unresolved → hold, log nothing.

    expect(app(CompileMatch::class)->run(COMPILE_FIXTURE_TOKEN))->toBeNull();
});
