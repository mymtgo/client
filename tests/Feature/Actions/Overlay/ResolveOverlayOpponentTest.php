<?php

use App\Actions\Overlay\ResolveOverlayOpponent;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
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

/**
 * Build a locally-downloaded archetype deck with 15 distinct non-land cards
 * (mtgo_ids 1001..1015), mirroring EstimateArchetypeLocallyTest's fixtures.
 * Big enough that observing 9 of them clears the confidence floor, and
 * observing 2 of them stays under it.
 *
 * @return array{0: Archetype, 1: ArchetypeDeck}
 */
function overlayLiveArchetypeDeck(string $name, string $format): array
{
    $archetype = Archetype::factory()->create(['name' => $name, 'format' => $format]);
    $deck = ArchetypeDeck::factory()->for($archetype)->create();

    $pivotData = [];
    for ($i = 1; $i <= 15; $i++) {
        $card = Card::factory()->create([
            'mtgo_id' => 1000 + $i,
            'oracle_id' => "overlay-live-{$i}",
            'type' => 'Instant',
        ]);
        $pivotData[$card->id] = ['quantity' => 4, 'sideboard' => false];
    }

    $deck->cards()->sync($pivotData);

    return [$archetype, $deck];
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

it('resolves the league archetype by uuid, not by a same-named archetype in another format', function () {
    // Created first and shares the name the API will return, so a name-only
    // lookup (the original bug) would resolve to this row instead.
    Archetype::factory()->create([
        'uuid' => 'wrong-format-uuid',
        'name' => 'Mono Red Prowess',
        'format' => 'pauper',
        'color_identity' => 'U',
    ]);

    $correct = Archetype::factory()->create([
        'uuid' => 'correct-uuid',
        'name' => 'Mono Red Prowess',
        'format' => 'modern',
        'color_identity' => 'R',
    ]);

    [$match] = overlayMatchWithOpponent('tok-uuid-collision');

    Http::fake([
        '*/api/players' => Http::response([
            'data' => ['league_result' => ['archetype' => ['uuid' => 'correct-uuid', 'name' => 'Mono Red Prowess']]],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->source)->toBe('league');
    expect($result->archetypeId)->toBe($correct->id);
    expect($result->archetypeColors)->toBe('R');
});

it('prefers a confident live estimate over a faked league hit', function () {
    [$liveArchetype] = overlayLiveArchetypeDeck('Live Local Combo', 'modern');

    Archetype::factory()->create([
        'uuid' => 'league-loser-uuid',
        'name' => 'League Loser',
        'format' => 'modern',
        'color_identity' => 'U',
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => 'm-tok-live', 'token' => 'tok-live', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);
    $opponent = Player::create(['username' => 'live-opp']);
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-tok-live', 'started_at' => now()]);

    // deck_json must be a plain array, never json_encode(...): Game::players()
    // uses the GamePlayer pivot class, which casts deck_json to 'array', so a
    // pre-encoded string is encoded twice and reads back as a string.
    // 9 of the deck's 15 distinct cards revealed — well above the confidence floor.
    $game->players()->attach($opponent->id, [
        'is_local' => 0,
        'instance_id' => 'i-2',
        'deck_json' => collect(range(1, 9))
            ->map(fn (int $i) => ['mtgo_id' => 1000 + $i, 'quantity' => 4])
            ->all(),
    ]);

    Http::fake([
        '*/api/players' => Http::response([
            'data' => ['league_result' => ['archetype' => ['uuid' => 'league-loser-uuid', 'name' => 'League Loser']]],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match->fresh());

    expect($result->source)->toBe('live');
    expect($result->archetypeId)->toBe($liveArchetype->id);
});

it('falls through past a live estimate below the confidence floor', function () {
    overlayLiveArchetypeDeck('Big Modern Deck', 'modern');

    $winner = Archetype::factory()->create([
        'uuid' => 'league-winner-uuid',
        'name' => 'League Winner',
        'format' => 'modern',
        'color_identity' => 'G',
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => 'm-tok-thin', 'token' => 'tok-thin', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);
    $opponent = Player::create(['username' => 'thin-opp']);
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-tok-thin', 'started_at' => now()]);

    // Only 2 of the deck's 15 distinct cards revealed — mirrors
    // EstimateArchetypeLocallyTest's "does not short-circuit on thin
    // observations" case, which lands confidence below the 0.8 floor.
    $game->players()->attach($opponent->id, [
        'is_local' => 0,
        'instance_id' => 'i-2',
        'deck_json' => [
            ['mtgo_id' => 1001, 'quantity' => 4],
            ['mtgo_id' => 1002, 'quantity' => 4],
        ],
    ]);

    Http::fake([
        '*/api/players' => Http::response([
            'data' => ['league_result' => ['archetype' => ['uuid' => 'league-winner-uuid', 'name' => 'League Winner']]],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match->fresh());

    expect($result->source)->toBe('league');
    expect($result->archetypeId)->toBe($winner->id);
});

/**
 * A live match whose opponent has revealed only 2 of the 15 cards in the
 * locally-downloaded list built by overlayLiveArchetypeDeck() — thin enough
 * that EstimateArchetypeLocally lands below the 0.8 confidence floor, so
 * resolution falls through to the league and then the API.
 *
 * @return array{0: MtgoMatch, 1: Player}
 */
function overlayThinlyRevealedMatch(string $token): array
{
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);
    $opponent = Player::create(['username' => 'opp-'.$token]);
    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now()]);

    $game->players()->attach($opponent->id, [
        'is_local' => 0,
        'instance_id' => 'i-2',
        'deck_json' => [
            ['mtgo_id' => 1001, 'quantity' => 4],
            ['mtgo_id' => 1002, 'quantity' => 4],
        ],
    ]);

    return [$match->fresh(), $opponent];
}

it('falls back to an API estimate when the local guess is thin and no league list exists', function () {
    overlayLiveArchetypeDeck('Big Modern Deck', 'modern');

    $guessed = Archetype::factory()->create([
        'uuid' => 'api-guess-uuid',
        'name' => 'API Guess',
        'format' => 'modern',
        'color_identity' => 'BR',
    ]);

    [$match] = overlayThinlyRevealedMatch('tok-api');

    Http::fake([
        '*/api/players' => Http::response([], 404),
        '*/api/archetypes/estimate' => Http::response([
            ['uuid' => 'api-guess-uuid', 'confidence' => 0.5, 'deck_version_uuid' => null],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->source)->toBe('api');
    expect($result->archetypeId)->toBe($guessed->id);
    expect($result->archetypeName)->toBe('API Guess');
    expect($result->manual)->toBeFalse();
});

it('prefers a league list over the API estimate', function () {
    overlayLiveArchetypeDeck('Big Modern Deck', 'modern');

    Archetype::factory()->create(['uuid' => 'api-guess-uuid', 'name' => 'API Guess', 'format' => 'modern']);
    $league = Archetype::factory()->create(['uuid' => 'league-uuid', 'name' => 'League List', 'format' => 'modern']);

    [$match] = overlayThinlyRevealedMatch('tok-api-vs-league');

    Http::fake([
        '*/api/players' => Http::response([
            'data' => ['league_result' => ['archetype' => ['uuid' => 'league-uuid', 'name' => 'League List']]],
        ]),
        '*/api/archetypes/estimate' => Http::response([
            ['uuid' => 'api-guess-uuid', 'confidence' => 1.0, 'deck_version_uuid' => null],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->source)->toBe('league');
    expect($result->archetypeId)->toBe($league->id);
});

it('never calls the API estimator when stats sharing is switched off', function () {
    overlayLiveArchetypeDeck('Big Modern Deck', 'modern');

    Archetype::factory()->create(['uuid' => 'api-guess-uuid', 'name' => 'API Guess', 'format' => 'modern']);

    AppSettings::setOffline(true);

    [$match] = overlayThinlyRevealedMatch('tok-api-off');

    Http::fake([
        '*/api/players' => Http::response([], 404),
        '*/api/archetypes/estimate' => Http::response([
            ['uuid' => 'api-guess-uuid', 'confidence' => 1.0, 'deck_version_uuid' => null],
        ]),
    ]);

    $result = ResolveOverlayOpponent::run($match);

    expect($result->source)->toBe('none');
    expect($result->archetypeId)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/archetypes/estimate'));
});
