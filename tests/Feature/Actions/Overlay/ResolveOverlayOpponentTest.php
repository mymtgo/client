<?php

use App\Actions\Overlay\ResolveOverlayOpponent;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // tests/Pest.php installs a blanket Http::fake() that always matches and
    // therefore shadows any URL-specific fake registered inside a test (see
    // FetchOpponentLeagueArchetypeTest for the same workaround). Reset the
    // stub list so per-test Http::fake([...]) calls actually take effect.
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

function overlayMatchWithOpponent(string $token, ?Player $opponent = null): array
{
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);

    $opponent ??= Player::create(['username' => 'opp-'.$token]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    return [$match->fresh(), $opponent];
}

it('returns null when no opponent player exists yet', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-none', 'token' => 'tok-none', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);

    expect(ResolveOverlayOpponent::run($match))->toBeNull();
});

it('prefers a manual archetype over everything else', function () {
    [$match, $opponent] = overlayMatchWithOpponent('tok-manual');

    $picked = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern', 'color_identity' => 'WUB']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $picked->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->archetypeId)->toBe($picked->id);
    expect($result->archetypeName)->toBe('Esper Blink');
    expect($result->archetypeColors)->toBe('WUB');
    expect($result->source)->toBe('manual');
    expect($result->manual)->toBeTrue();
});

it('falls back to the last archetype this opponent was seen on', function () {
    $opponent = Player::create(['username' => 'repeat-opp']);

    $seen = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern', 'color_identity' => 'R']);

    [$oldMatch] = overlayMatchWithOpponent('tok-old', $opponent);
    MatchArchetype::create([
        'mtgo_match_id' => $oldMatch->id,
        'player_id' => $opponent->id,
        'archetype_id' => $seen->id,
        'confidence' => 0.9,
    ]);

    [$match] = overlayMatchWithOpponent('tok-new', $opponent);

    Http::fake(['*/api/players' => Http::response([], 404)]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->archetypeId)->toBe($seen->id);
    expect($result->source)->toBe('local');
});

it('reports the head-to-head record even when the league lookup wins', function () {
    $opponent = Player::create(['username' => 'leagueWinner']);

    Archetype::factory()->create([
        'uuid' => 'arch-league',
        'name' => 'Mono Red Prowess',
        'format' => 'modern',
        'color_identity' => 'R',
    ]);

    // Two completed matches against this opponent: one win, one loss.
    foreach ([['tok-h2h-w', MatchOutcome::Win], ['tok-h2h-l', MatchOutcome::Loss]] as [$token, $outcome]) {
        $past = MtgoMatch::create([
            'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
            'match_type' => 'League', 'state' => MatchState::Complete,
            'started_at' => now()->subHour(), 'outcome' => $outcome,
        ]);

        $game = Game::create(['match_id' => $past->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now()->subHour()]);
        $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);
    }

    [$match] = overlayMatchWithOpponent('tok-h2h-live', $opponent);

    Http::fake([
        '*/api/players' => Http::response([
            'data' => ['league_result' => ['archetype' => ['uuid' => 'arch-league', 'name' => 'Mono Red Prowess']]],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->source)->toBe('league');
    expect($result->archetypeName)->toBe('Mono Red Prowess');
    expect($result->wins)->toBe(1);
    expect($result->losses)->toBe(1);
    expect($result->previousMatches)->toBe(2);
});

it('reports no archetype when nothing resolves', function () {
    [$match] = overlayMatchWithOpponent('tok-empty');

    Http::fake(['*/api/players' => Http::response([], 404)]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->archetypeId)->toBeNull();
    expect($result->source)->toBe('none');
    expect($result->manual)->toBeFalse();
});
