<?php

use App\Actions\Cards\EnqueueCardStats;
use App\Actions\Matches\SubmitMatchToApi;
use App\Models\Archetype;
use App\Models\CardStatShipQueue;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\CardStatsTelemetryFactory;

uses(RefreshDatabase::class);

function makeManualMatchWithArchetype(): MtgoMatch
{
    $version = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'manual' => true,
    ]);

    $local = Player::factory()->create();
    $opponent = Player::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    $game->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'on_play' => true, 'starting_hand_size' => 7, 'deck_json' => []]);
    $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false, 'starting_hand_size' => 7, 'deck_json' => []]);

    $match->archetypes()->create([
        'archetype_id' => Archetype::factory()->create()->id,
        'player_id' => $local->id,
        'confidence' => 1.0,
    ]);
    $match->archetypes()->create([
        'archetype_id' => Archetype::factory()->create()->id,
        'player_id' => $opponent->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    return $match;
}

it('excludes manual matches from the submittable scope', function () {
    $manual = makeManualMatchWithArchetype();
    $tracked = makeManualMatchWithArchetype();
    $tracked->update(['manual' => false]);

    $ids = MtgoMatch::submittable()->pluck('id');

    expect($ids)->toContain($tracked->id)
        ->not->toContain($manual->id);
});

it('never posts a manual match to the API', function () {
    Http::fake();
    $match = makeManualMatchWithArchetype();

    SubmitMatchToApi::run($match->id);

    Http::assertNothingSent();
    expect($match->fresh()->submitted_at)->toBeNull();
});

it('never enqueues card stats for manual match games', function () {
    CardStatsTelemetryFactory::make(matchOverrides: ['manual' => true]);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});
