<?php

use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

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
