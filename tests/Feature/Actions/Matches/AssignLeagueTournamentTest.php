<?php

use App\Actions\Matches\AssignLeague;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not assign a league when the match has a tournament Description', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);

    $gameMeta = [
        'Description' => 'Tournament:12839688 Round:3',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
    ];

    AssignLeague::run($match, $gameMeta);

    expect($match->fresh()->league_id)->toBeNull();
    expect(League::count())->toBe(0);
});

it('skips league assignment when tournament_event_id is stamped on the match (gameMeta empty)', function () {
    // Real single-line tournament logs don't parse through ExtractKeyValueBlock,
    // so gameMeta.Description can be empty. AdvanceMatchState stamps the
    // match column directly — the exclusion must use that as the primary signal.
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Started,
        'tournament_event_id' => 12839688,
        'tournament_round' => 3,
    ]);

    AssignLeague::run($match, []);

    expect($match->fresh()->league_id)->toBeNull();
    expect(League::count())->toBe(0);
});

it('still creates a phantom league for non-tournament matches', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started, 'format' => 'CMODERN']);

    AppSettings::setHidePhantomLeagues(false);

    $gameMeta = [
        'Description' => 'LeagueMatch',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
    ];

    AssignLeague::run($match, $gameMeta);

    expect($match->fresh()->league_id)->not->toBeNull();
});
