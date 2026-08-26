<?php

use App\Enums\LeagueKind;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function noteFixture(): array
{
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'picks_expected' => 42]);
    $pick = DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 7]);

    return [$league, $pick];
}

it('saves a note on a pick', function () {
    [$league, $pick] = noteFixture();

    $this->from(route('limited.draft', ['league' => $league->id]))
        ->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 7]), ['note' => '  Bard over the land  '])
        ->assertRedirect(route('limited.draft', ['league' => $league->id]));

    expect($pick->fresh()->note)->toBe('Bard over the land');
});

it('clears a note with an empty string', function () {
    [$league, $pick] = noteFixture();
    $pick->update(['note' => 'old']);

    $this->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 7]), ['note' => '']);

    expect($pick->fresh()->note)->toBeNull();
});

it('validates ordinal range and note length', function () {
    [$league] = noteFixture();

    $this->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 43]), ['note' => 'x'])->assertNotFound();
    $this->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 0]), ['note' => 'x'])->assertNotFound();
    $this->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 8]), ['note' => 'x'])->assertNotFound();
    $this->from('/limited')->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 7]), ['note' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('note');
});

it('rejects constructed leagues', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);

    $this->patch(route('limited.picks.note', ['league' => $league->id, 'ordinal' => 1]), ['note' => 'x'])->assertNotFound();
});
