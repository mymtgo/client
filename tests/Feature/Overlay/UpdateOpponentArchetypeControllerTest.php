<?php

use App\Enums\MatchState;
use App\Jobs\DownloadArchetypeDecklists;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function pickableMatch(string $token): array
{
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);

    $opponent = Player::create(['username' => 'opp-'.$token]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    return [$match, $opponent];
}

it('stores a manual archetype for the live match opponent', function () {
    Queue::fake();

    [$match, $opponent] = pickableMatch('tok-pick');
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    $this->post(route('overlay.archetype'), ['archetype_id' => $archetype->id])
        ->assertRedirect();

    $row = MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('player_id', $opponent->id)
        ->sole();

    expect($row->archetype_id)->toBe($archetype->id);
    expect($row->manual)->toBeTrue();
    expect((float) $row->confidence)->toBe(1.0);

    Queue::assertPushed(DownloadArchetypeDecklists::class);
});

it('is idempotent across repeat picks', function () {
    Queue::fake();

    [$match, $opponent] = pickableMatch('tok-repeat');
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    $this->post(route('overlay.archetype'), ['archetype_id' => $archetype->id]);
    $this->post(route('overlay.archetype'), ['archetype_id' => $archetype->id]);

    expect(MatchArchetype::where('mtgo_match_id', $match->id)->count())->toBe(1);
});

it('stores the merged-into archetype when the pick has been merged away', function () {
    Queue::fake();

    [$match, $opponent] = pickableMatch('tok-merged');

    $parent = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $merged = Archetype::factory()->create([
        'name' => 'Esper Blink (old)', 'format' => 'modern', 'merged_into_id' => $parent->id,
    ]);

    $this->post(route('overlay.archetype'), ['archetype_id' => $merged->id])->assertRedirect();

    expect(MatchArchetype::where('mtgo_match_id', $match->id)->sole()->archetype_id)->toBe($parent->id);
});

it('rejects an unknown archetype', function () {
    pickableMatch('tok-bad');

    $this->post(route('overlay.archetype'), ['archetype_id' => 999999])
        ->assertSessionHasErrors('archetype_id');
});

it('does nothing when no match is live', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    $this->post(route('overlay.archetype'), ['archetype_id' => $archetype->id])->assertRedirect();

    expect(MatchArchetype::count())->toBe(0);
});
