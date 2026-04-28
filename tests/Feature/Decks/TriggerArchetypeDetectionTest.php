<?php

use App\Actions\QueueArchetypeDetectionForDeck;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function trigArchSetupMatchWithOpponent(int $deckVersionId, Player $opponent): MtgoMatch
{
    $match = MtgoMatch::factory()->create(['deck_version_id' => $deckVersionId]);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
    ]);

    return $match;
}

it('queues detection only for matches with no opponent archetype when filter is "none"', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();
    $opponent = Player::create(['username' => 'TrigOpp1']);

    $unknown = trigArchSetupMatchWithOpponent($version->id, $opponent);
    $known = trigArchSetupMatchWithOpponent($version->id, $opponent);

    MatchArchetype::create([
        'mtgo_match_id' => $known->id,
        'player_id' => $opponent->id,
        'archetype_id' => Archetype::factory()->create()->id,
        'confidence' => 0.9,
    ]);

    $count = app(QueueArchetypeDetectionForDeck::class)($deck, 'none');

    expect($count)->toBe(1);
    expect($unknown->fresh()->archetype_detection_queued_at)->not->toBeNull();
    expect($known->fresh()->archetype_detection_queued_at)->toBeNull();

    Queue::assertPushed(DetermineMatchArchetypesJob::class, 1);
    Queue::assertPushed(
        DetermineMatchArchetypesJob::class,
        fn (DetermineMatchArchetypesJob $job) => $job->matchId === $unknown->id,
    );
});

it('queues detection only for matches whose opponent has the given archetype id', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();
    $opponent = Player::create(['username' => 'TrigOpp2']);
    $targetArch = Archetype::factory()->create();
    $otherArch = Archetype::factory()->create();

    $matchA = trigArchSetupMatchWithOpponent($version->id, $opponent);
    $matchB = trigArchSetupMatchWithOpponent($version->id, $opponent);

    MatchArchetype::create([
        'mtgo_match_id' => $matchA->id,
        'player_id' => $opponent->id,
        'archetype_id' => $targetArch->id,
        'confidence' => 0.9,
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $matchB->id,
        'player_id' => $opponent->id,
        'archetype_id' => $otherArch->id,
        'confidence' => 0.9,
    ]);

    $count = app(QueueArchetypeDetectionForDeck::class)($deck, (string) $targetArch->id);

    expect($count)->toBe(1);
    expect($matchA->fresh()->archetype_detection_queued_at)->not->toBeNull();
    expect($matchB->fresh()->archetype_detection_queued_at)->toBeNull();

    Queue::assertPushed(
        DetermineMatchArchetypesJob::class,
        fn (DetermineMatchArchetypesJob $job) => $job->matchId === $matchA->id,
    );
});

it('returns 0 when no matches match the filter', function () {
    Queue::fake();

    $deck = Deck::factory()->create();

    $count = app(QueueArchetypeDetectionForDeck::class)($deck, 'none');

    expect($count)->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects request without filter_archetype', function () {
    $deck = Deck::factory()->create();

    $response = $this->postJson(
        route('decks.archetypes.detect', ['deck' => $deck->id]),
        []
    );

    $response->assertStatus(422);
});

it('rejects request with filter_archetype=all', function () {
    $deck = Deck::factory()->create();

    $response = $this->postJson(
        route('decks.archetypes.detect', ['deck' => $deck->id]),
        ['filter_archetype' => 'all']
    );

    $response->assertStatus(422);
});

it('flashes the queued count back to the page', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();
    $opponent = Player::create(['username' => 'TrigOppFlash']);

    trigArchSetupMatchWithOpponent($version->id, $opponent);
    trigArchSetupMatchWithOpponent($version->id, $opponent);

    $response = $this->from(route('decks.matches', ['deck' => $deck->id]))
        ->post(
            route('decks.archetypes.detect', ['deck' => $deck->id]),
            ['filter_archetype' => 'none']
        );

    $response->assertRedirect(route('decks.matches', ['deck' => $deck->id]));
    $response->assertSessionHas('archetypeDetectionQueued', 2);
});

it('exposes pendingArchetypeCount as a page prop scoped to the deck', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    MtgoMatch::factory()->count(2)->state([
        'deck_version_id' => $version->id,
        'archetype_detection_queued_at' => now(),
    ])->create();

    MtgoMatch::factory()->state([
        'deck_version_id' => $version->id,
        'archetype_detection_queued_at' => null,
    ])->create();

    $otherDeck = Deck::factory()->create();
    $otherVersion = DeckVersion::factory()->for($otherDeck)->create();
    MtgoMatch::factory()->state([
        'deck_version_id' => $otherVersion->id,
        'archetype_detection_queued_at' => now(),
    ])->create();

    $response = $this->get(route('decks.matches', ['deck' => $deck->id]));

    $response->assertInertia(fn ($page) => $page
        ->where('pendingArchetypeCount', 2)
    );
});
