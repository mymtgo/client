<?php

use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
});

function attachOpponent(MtgoMatch $match, string $username, ?int $instanceId = null): Player
{
    $opponent = Player::create(['username' => $username]);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => $instanceId ?? 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [],
    ]);

    return $opponent;
}

it('returns 10 most recent format-scoped matches by default', function () {
    foreach (range(1, 12) as $i) {
        $match = MtgoMatch::factory()->create([
            'format' => 'CMODERN',
            'started_at' => now()->subMinutes($i),
        ]);
        attachOpponent($match, "opp$i");
    }

    $response = $this->get('/archetypes/create?format=modern');

    $response->assertOk();
    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('archetypes/Create')
            ->has('matches', 10)
            ->where('matches.0.opponent_username', 'opp1')
    );
});

it('returns no matches when no format is selected', function () {
    $match = MtgoMatch::factory()->create(['format' => 'CMODERN']);
    attachOpponent($match, 'opp');

    $response = $this->get('/archetypes/create');

    $response->assertInertia(
        fn (AssertableInertia $page) => $page->where('matches', [])
    );
});

it('filters matches by opponent username substring when match_search provided', function () {
    $first = MtgoMatch::factory()->create(['format' => 'CMODERN', 'started_at' => now()->subMinute()]);
    attachOpponent($first, 'AlphaPlayer');

    $second = MtgoMatch::factory()->create(['format' => 'CMODERN', 'started_at' => now()->subMinutes(2)]);
    attachOpponent($second, 'BetaPlayer');

    $response = $this->get('/archetypes/create?format=modern&match_search=alph');

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('matches', 1)
            ->where('matches.0.opponent_username', 'AlphaPlayer')
    );
});

it('returns prefill data when source_match_id is provided', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-bolt',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'image' => null,
                    'art_crop' => null,
                    'cmc' => 1,
                    'identity' => 'R',
                ],
            ],
        ]),
    ]);

    $match = MtgoMatch::factory()->create(['format' => 'CMODERN']);
    $opponent = Player::create(['username' => 'opponent']);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 12345, 'quantity' => 2],
        ],
    ]);

    $response = $this->get('/archetypes/create?source_match_id='.$match->id);

    $response->assertOk();
    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('prefill.source_match_id', $match->id)
            ->where('prefill.format', 'modern')
            ->where('prefill.color_identity', 'R')
            ->has('prefill.cards', 1)
            ->where('prefill.cards.0.name', 'Lightning Bolt')
    );
});

it('returns null prefill when source match has no opponent cards', function () {
    $match = MtgoMatch::factory()->create(['format' => 'CMODERN']);

    $response = $this->get('/archetypes/create?source_match_id='.$match->id);

    $response->assertOk();
    $response->assertInertia(
        fn (AssertableInertia $page) => $page->where('prefill', null)
    );
});

it('returns null prefill when no source_match_id provided', function () {
    $response = $this->get('/archetypes/create');

    $response->assertInertia(
        fn (AssertableInertia $page) => $page->where('prefill', null)
    );
});

it('only includes complete matches', function () {
    $complete = MtgoMatch::factory()->create(['format' => 'CMODERN', 'state' => MatchState::Complete, 'started_at' => now()->subMinute()]);
    attachOpponent($complete, 'CompleteOpp');

    $started = MtgoMatch::factory()->started()->create(['format' => 'CMODERN', 'started_at' => now()->subMinutes(2)]);
    attachOpponent($started, 'StartedOpp');

    $response = $this->get('/archetypes/create?format=modern');

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('matches', 1)
            ->where('matches.0.opponent_username', 'CompleteOpp')
    );
});
