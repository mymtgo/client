<?php

use App\Actions\Drafts\ResolveDraftLeague;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('replaces a placeholder token once the real league token is known', function () {
    $league = League::factory()->create([
        'token' => 'draft-11039-35746768',
        'event_id' => 11039,
        'mtgo_course_id' => 35746768,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
    ]);

    ResolveDraftLeague::run(11039, 35746768, null, '48a2e914-f2ee-4fce-a4ad-47e396488889');

    expect($league->fresh()->token)->toBe('48a2e914-f2ee-4fce-a4ad-47e396488889')
        ->and(League::count())->toBe(1);
});

it('never overwrites a real token', function () {
    $league = League::factory()->create([
        'token' => 'first-real-token',
        'event_id' => 11039,
        'mtgo_course_id' => 35746768,
        'kind' => LeagueKind::Draft,
    ]);

    ResolveDraftLeague::run(11039, 35746768, null, 'second-real-token');

    expect($league->fresh()->token)->toBe('first-real-token');
});

it('mints a placeholder token when no real one is known yet', function () {
    $league = ResolveDraftLeague::run(11039, 35746768);

    expect($league->token)->toBe('draft-11039-35746768');
});
