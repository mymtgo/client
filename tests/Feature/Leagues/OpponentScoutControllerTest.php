<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Cache::flush();

    AppSettings::setDeviceId('device-123');
    AppSettings::setApiKey('key-abc');
    AppSettings::setApiKeyExpiresAt(now()->addHour()->toIso8601String());
});

function attachScoutOpponent(MtgoMatch $match, Player $opponent): void
{
    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'g-'.$match->id,
        'started_at' => now(),
    ]);

    $game->players()->attach($opponent->id, [
        'is_local' => 0,
        'instance_id' => 'i-'.$opponent->id,
    ]);
}

function drawOddsSignature(array $rows): string
{
    return base64_encode(collect($rows)->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")->implode('|'));
}

it('renders league archetype when API returns a 5-0 hit', function () {
    Archetype::factory()->create([
        'uuid' => 'arch-1',
        'name' => 'Mono Red Prowess',
        'format' => 'modern',
        'color_identity' => 'R',
    ]);

    $opponent = Player::create(['username' => 'leagueWinner']);

    $match = MtgoMatch::create([
        'mtgo_id' => '300001',
        'token' => 'mt-1',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    attachScoutOpponent($match, $opponent);

    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'league_result' => [
                    'archetype' => [
                        'uuid' => 'arch-1',
                        'name' => 'Mono Red Prowess',
                        'slug' => 'mono-red-prowess',
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->get(route('leagues.opponent-scout'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/OpponentScout')
        ->where('opponent.username', 'leagueWinner')
        ->where('opponent.lastArchetype', 'Mono Red Prowess')
        ->where('opponent.lastArchetypeColors', 'R')
        ->where('opponent.source', 'league')
    );
});

it('falls back to local data when API returns 404', function () {
    $opponent = Player::create(['username' => 'noLeagueGuy']);

    $match = MtgoMatch::create([
        'mtgo_id' => '300002',
        'token' => 'mt-2',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    attachScoutOpponent($match, $opponent);

    Http::fake([
        '*/api/players' => Http::response(['message' => 'not found'], 404),
    ]);

    $response = $this->get(route('leagues.opponent-scout'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/OpponentScout')
        ->where('opponent.username', 'noLeagueGuy')
        ->where('opponent.source', 'local')
    );
});

it('caches API result so subsequent polls do not re-fetch', function () {
    Archetype::factory()->create([
        'uuid' => 'arch-2',
        'name' => 'Living End',
        'format' => 'modern',
        'color_identity' => 'BGR',
    ]);

    $opponent = Player::create(['username' => 'cachedFoe']);

    $match = MtgoMatch::create([
        'mtgo_id' => '300003',
        'token' => 'mt-3',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    attachScoutOpponent($match, $opponent);

    Http::fake([
        '*/api/players' => Http::response([
            'data' => [
                'league_result' => [
                    'archetype' => [
                        'uuid' => 'arch-2',
                        'name' => 'Living End',
                        'slug' => 'living-end',
                    ],
                ],
            ],
        ]),
    ]);

    $this->get(route('leagues.opponent-scout'))->assertOk();
    $this->get(route('leagues.opponent-scout'))->assertOk();
    $this->get(route('leagues.opponent-scout'))->assertOk();

    Http::assertSentCount(1);
});

it('renders null opponent when no active match exists', function () {
    $response = $this->get(route('leagues.opponent-scout'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/OpponentScout')
        ->where('opponent', null)
        ->where('drawOdds', null)
    );
});

it('renders draw odds for the active match deck', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => drawOddsSignature([['101', '20', '0'], ['102', '4', '0']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '500001', 'token' => 'mt-s1', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $this->get(route('leagues.opponent-scout'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leagues/OpponentScout')
            ->where('drawOdds.librarySize', 24)
            ->has('drawOdds.cards', 2)
        );
});
