<?php

use App\Jobs\ComputeCardGameStats;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Updates\RegenerateCardStatsWithCastingMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a game-log stats recompute for every complete match', function () {
    Queue::fake();

    $deckVersion = DeckVersion::factory()->create();

    $complete = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
    ]);
    Game::factory()->for($complete, 'match')->create(['won' => true, 'started_at' => now()]);

    // No games — must not be queued.
    MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
    ]);

    (new RegenerateCardStatsWithCastingMethods)->run();

    Queue::assertPushed(ComputeCardGameStats::class, 1);
    Queue::assertPushed(fn (ComputeCardGameStats $job) => $job->matchId === $complete->id && $job->fromGameLog === true);
});
