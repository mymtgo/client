<?php

use App\Models\Account;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Services\Sync\SyncTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
});

/**
 * A game local.player (login 3000001) played against Opp_Name. The seat-2
 * name in the frames is Opp_Name unless a test passes a variant. The game
 * has no end time, so it carries no game log: GetGameLogEntries reads
 * decoded .dat files that a test cannot provide.
 */
function shareableGame(string $opponentFrameName = 'Opp_Name'): Game
{
    Account::factory()->create(['username' => 'local.player', 'login_id' => 3000001]);
    $match = MtgoMatch::factory()->create(['token' => 'a1b2c3d4-0000-4000-8000-000000000001']);
    $game = Game::factory()->for($match, 'match')->create(['won' => true, 'ended_at' => null]);
    $local = Player::factory()->create(['username' => 'local.player']);
    $opponent = Player::factory()->create(['username' => 'Opp_Name']);
    $game->players()->attach($local->id, ['instance_id' => 1, 'is_local' => true, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['instance_id' => 2, 'is_local' => false, 'on_play' => false]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '2026-09-20 18:00:01',
        'content' => ['Players' => [['Id' => 1, 'Name' => 'local.player'], ['Id' => 2, 'Name' => $opponentFrameName]], 'Cards' => []],
    ]);

    return $game;
}

function sharedResponse(): array
{
    return ['uuid' => '5f0c6a8e-1111-4000-8000-000000000001', 'url' => 'https://mymtgo.com/players/local.player/replays/5f0c6a8e-1111-4000-8000-000000000001'];
}

it('uploads the redacted match as gzip and stores the link', function () {
    $game = shareableGame();
    $matchUpdatedAt = $game->match->fresh()->updated_at->toIso8601String();
    $this->travel(5)->minutes();
    Http::fake(['*/api/replays' => Http::response(['message' => 'Replay shared.', ...sharedResponse()], 201)]);

    $this->post(route('games.share', $game))->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(function (Request $request) {
        $body = json_decode(gzdecode($request->body()), true);

        return $request->hasHeader('Content-Encoding', 'gzip')
            && $body['client_match_key'] === 'a1b2c3d4-0000-4000-8000-000000000001'
            && $body['login_id'] === 3000001
            && ! str_contains(json_encode($body['snapshot']), 'Opp_Name')
            && $body['snapshot']['games'][0]['frames'][0]['content']['Players'][1]['Name'] === 'Opponent';
    });

    expect($game->match->fresh()->replay_share_uuid)->toBe(sharedResponse()['uuid'])
        ->and($game->match->fresh()->replay_share_url)->toBe(sharedResponse()['url'])
        // The link is local bookkeeping: the match must not look edited to cloud sync.
        ->and($game->match->fresh()->updated_at->toIso8601String())->toBe($matchUpdatedAt);
});

it('uploads again on a repeat share so later games are included', function () {
    $game = shareableGame();
    $game->match->update(['replay_share_uuid' => sharedResponse()['uuid'], 'replay_share_url' => sharedResponse()['url']]);
    Http::fake(['*/api/replays' => Http::response(sharedResponse())]);

    $this->post(route('games.share', $game))->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    expect($game->match->fresh()->replay_share_url)->toBe(sharedResponse()['url']);
});

it('uploads nothing when the opponent name cannot be hidden', function () {
    $game = shareableGame('xOpp_Name');
    Http::fake();

    $this->post(route('games.share', $game))->assertSessionHasErrors('share');

    Http::assertNothingSent();
    expect($game->match->fresh()->replay_share_uuid)->toBeNull();
});

it('explains each server refusal', function (int $status, array $body, string $phrase) {
    $game = shareableGame();
    Http::fake(['*/api/replays' => Http::response($body, $status)]);

    $this->post(route('games.share', $game))->assertSessionHasErrors('share');

    expect(session('errors')->first('share'))->toContain($phrase);

    expect($game->match->fresh()->replay_share_uuid)->toBeNull();
})->with([
    'free account' => [422, ['error' => 'replay_share_requires_supporter'], 'supporter'],
    'player not claimed' => [409, ['error' => 'player_not_claimed'], 'claim'],
    'too large' => [413, ['error' => 'replay_too_large'], 'too large'],
]);

it('asks to sign in when the device is not linked', function () {
    app(SyncTokens::class)->clear();
    Http::fake();

    $this->post(route('games.share', shareableGame()))->assertSessionHasErrors('share');

    expect(session('errors')->first('share'))->toContain('Sign in');

    Http::assertNothingSent();
});

it('refuses when the local MTGO login id is unknown', function () {
    $game = shareableGame();
    Account::query()->update(['login_id' => null]);
    Http::fake();

    $this->post(route('games.share', $game))->assertSessionHasErrors('share');

    Http::assertNothingSent();
});

it('switches the link off and forgets it', function (int $status) {
    $game = shareableGame();
    $game->match->update(['replay_share_uuid' => sharedResponse()['uuid'], 'replay_share_url' => sharedResponse()['url'], 'replay_shared_at' => now()]);
    Http::fake(['*/api/replays/*' => Http::response(null, $status)]);

    $this->delete(route('games.share.destroy', $game))->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/replays/'.sharedResponse()['uuid']));
    expect($game->match->fresh()->replay_share_uuid)->toBeNull()
        ->and($game->match->fresh()->replay_share_url)->toBeNull();
})->with([
    'revoked now' => [204],
    'already gone' => [404],
]);

it('passes the share state to the replay page, linking to the game on screen', function () {
    $game = shareableGame();
    $game->match->update(['replay_share_url' => sharedResponse()['url']]);

    $this->get(route('games.show', $game->id))
        ->assertInertia(fn ($page) => $page->where('share.linked', true)
            ->where('share.url', sharedResponse()['url'].'?game=1')
            ->has('share.supporter'));
});
