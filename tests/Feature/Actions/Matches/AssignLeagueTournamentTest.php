<?php

use App\Actions\Matches\AssignLeague;
use App\Enums\MatchState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

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

it('still creates a phantom league for non-tournament matches', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started, 'format' => 'CMODERN']);

    Settings::set('hide_phantom_leagues', false);

    $gameMeta = [
        'Description' => 'LeagueMatch',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
    ];

    AssignLeague::run($match, $gameMeta);

    expect($match->fresh()->league_id)->not->toBeNull();
});
