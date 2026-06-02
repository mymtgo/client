<?php

use App\Jobs\ComputeCardGameStats;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a ComputeCardGameStats job for each live, complete match in the deck', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $matchA = MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'imported' => false]);
    Game::factory()->create(['match_id' => $matchA->id]);

    $matchB = MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'imported' => false]);
    Game::factory()->create(['match_id' => $matchB->id]);

    $response = $this->from(route('decks.card-stats', ['deck' => $deck->id]))
        ->post(route('decks.card-stats.regenerate', ['deck' => $deck->id]));

    $response->assertRedirect(route('decks.card-stats', ['deck' => $deck->id]));
    $response->assertSessionHas('cardStatsRegenerated', 2);

    Queue::assertPushed(ComputeCardGameStats::class, 2);
    Queue::assertPushed(
        ComputeCardGameStats::class,
        fn (ComputeCardGameStats $job) => $job->matchId === $matchA->id && $job->fromGameLog === true,
    );
    Queue::assertPushed(
        ComputeCardGameStats::class,
        fn (ComputeCardGameStats $job) => $job->matchId === $matchB->id,
    );
});

it('skips matches from other decks', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $otherDeck = Deck::factory()->create();
    $otherVersion = DeckVersion::factory()->for($otherDeck)->create();

    $myMatch = MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'imported' => false]);
    Game::factory()->create(['match_id' => $myMatch->id]);

    $foreignMatch = MtgoMatch::factory()->create(['deck_version_id' => $otherVersion->id, 'imported' => false]);
    Game::factory()->create(['match_id' => $foreignMatch->id]);

    $this->post(route('decks.card-stats.regenerate', ['deck' => $deck->id]));

    Queue::assertPushed(ComputeCardGameStats::class, 1);
    Queue::assertPushed(
        ComputeCardGameStats::class,
        fn (ComputeCardGameStats $job) => $job->matchId === $myMatch->id,
    );
});

it('skips live matches that are not yet complete', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $complete = MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'imported' => false]);
    Game::factory()->create(['match_id' => $complete->id]);

    $inProgress = MtgoMatch::factory()->inProgress()->create(['deck_version_id' => $version->id, 'imported' => false]);
    Game::factory()->create(['match_id' => $inProgress->id]);

    $this->post(route('decks.card-stats.regenerate', ['deck' => $deck->id]));

    Queue::assertPushed(ComputeCardGameStats::class, 1);
    Queue::assertPushed(
        ComputeCardGameStats::class,
        fn (ComputeCardGameStats $job) => $job->matchId === $complete->id,
    );
});

it('flashes 0 and queues nothing when the deck has no matches', function () {
    Queue::fake();

    $deck = Deck::factory()->create();

    $response = $this->from(route('decks.card-stats', ['deck' => $deck->id]))
        ->post(route('decks.card-stats.regenerate', ['deck' => $deck->id]));

    $response->assertRedirect(route('decks.card-stats', ['deck' => $deck->id]));
    $response->assertSessionHas('cardStatsRegenerated', 0);

    Queue::assertNothingPushed();
});
