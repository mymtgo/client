<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use App\Services\Sync\Bundles\DeckBundleBuilder;
use App\Services\Sync\Bundles\LeagueBundleBuilder;
use App\Services\Sync\Bundles\MatchBundleBuilder;
use App\Services\Sync\DirtyRows;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A full 2-game match graph: a deck + version, a league link, two games,
 * one game_player row per game per side, two timelines, four card stats,
 * and two match_archetypes rows (one per side, distinct uuids). Creating
 * children bumps the parent's updated_at through the $touches cascade,
 * which is expected and not worked around here.
 */
function syncTestMatch(array $overrides = []): MtgoMatch
{
    // Matches only sync when their deck is enabled for cloud sync, so the
    // shared fixture is enabled by default; a test that wants the gated
    // case turns the flag off explicitly.
    $deck = Deck::factory()->create(['cloud_sync_enabled' => true]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create();

    $match = MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'result' => '2-1',
    ], $overrides));

    $local = Player::firstOrCreate(['username' => 'local_player'], ['is_player' => true]);
    $opponent = Player::firstOrCreate(['username' => 'opp_'.$match->token]);

    foreach ([1, 2] as $number) {
        $game = Game::factory()->create([
            'match_id' => $match->id,
            'mtgo_id' => 'game-'.$match->token.'-'.$number,
            'won' => $number === 1,
            'turn_count' => 7 + $number,
        ]);

        $game->players()->attach($local->id, [
            'is_local' => true,
            'on_play' => $number === 1,
            'starting_hand_size' => 7,
            'mulligan_count' => 0,
            'dice_roll' => 5,
            'deck_json' => null,
            'instance_id' => fake()->randomNumber(6),
        ]);
        $game->players()->attach($opponent->id, [
            'is_local' => false,
            'on_play' => $number !== 1,
            'starting_hand_size' => 7,
            'mulligan_count' => 1,
            'dice_roll' => 2,
            'deck_json' => null,
            'instance_id' => fake()->randomNumber(6),
        ]);

        GameTimeline::create(['game_id' => $game->id, 'timestamp' => now(), 'content' => ['turn' => $number]]);

        CardGameStat::create([
            'oracle_id' => 'oracle-'.$number.'-mine',
            'game_id' => $game->id,
            'deck_version_id' => $version->id,
            'quantity' => 4,
            'won' => true,
            'opponent' => false,
        ]);
        CardGameStat::create([
            'oracle_id' => 'oracle-'.$number.'-theirs',
            'game_id' => $game->id,
            'deck_version_id' => $version->id,
            'quantity' => 2,
            'won' => false,
            'opponent' => true,
        ]);
    }

    $playerArchetype = Archetype::factory()->create();
    $opponentArchetype = Archetype::factory()->create();

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $playerArchetype->id,
        'player_id' => $local->id,
        'confidence' => 1.0,
    ]);
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $opponentArchetype->id,
        'player_id' => $opponent->id,
        'confidence' => 0.5,
    ]);

    return $match->fresh();
}

/**
 * Two leagues sharing a token but with different started_at values, the
 * case (token, started_at) uniqueness exists to cover.
 *
 * @return array{0: League, 1: League}
 */
function syncTestLeaguePair(): array
{
    $token = (string) Str::uuid();

    $a = League::factory()->create(['token' => $token, 'started_at' => now()->subDays(2)]);
    $b = League::factory()->create(['token' => $token, 'started_at' => now()]);

    return [$a->fresh(), $b->fresh()];
}

function syncTestDeck(): Deck
{
    $deck = Deck::factory()->create(['original_name' => 'Original Name']);
    DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => now()]);

    return $deck->fresh();
}

it('builds a match bundle carrying games, players, timelines, card stats and archetype sides', function () {
    $match = syncTestMatch(); // helper below builds a full 2-game match with players, timelines, stats, both archetype sides

    $bundle = app(MatchBundleBuilder::class)->build($match);

    expect($bundle['match']['token'])->toBe($match->token)
        ->and($bundle['match'])->not->toHaveKeys(['id', 'deck_version_id', 'league_id', 'tournament_id', 'submitted_at', 'attempts', 'failed_at', 'archetype_detection_queued_at', 'created_at', 'updated_at', 'tournament_token'])
        ->and($bundle['games'])->toHaveCount(2)
        ->and($bundle['players'][0])->toHaveKeys(['game', 'username', 'is_local', 'on_play', 'starting_hand_size', 'mulligan_count', 'dice_roll', 'deck_json'])
        // Actual pivot values, not just key presence: game_player carries
        // mulligan_count and dice_roll, and Game::players() must expose
        // them via withPivot or every bundle emits null regardless of what
        // is stored.
        ->and($bundle['players'][0]['username'])->toBe('local_player')
        ->and($bundle['players'][0]['mulligan_count'])->toBe(0)
        ->and($bundle['players'][0]['dice_roll'])->toBe(5)
        ->and($bundle['players'][1]['username'])->toBe('opp_'.$match->token)
        ->and($bundle['players'][1]['mulligan_count'])->toBe(1)
        ->and($bundle['players'][1]['dice_roll'])->toBe(2)
        ->and($bundle['card_stats'][0])->not->toHaveKeys(['id', 'game_id', 'deck_version_id'])
        ->and($bundle['archetypes'])->toHaveCount(2)
        ->and($bundle['archetypes'][0]['confidence'])->toBeString();
});

it('is byte-stable across two builds of the same match', function () {
    $match = syncTestMatch();
    $builder = app(MatchBundleBuilder::class);

    expect(CanonicalJson::encode($builder->build($match->fresh())))
        ->toBe(CanonicalJson::encode($builder->build($match->fresh())));
});

it('does not churn the hash when excluded pipeline columns move', function () {
    $match = syncTestMatch();
    $builder = app(MatchBundleBuilder::class);
    $before = CanonicalJson::hash($builder->build($match->fresh()));

    $match->forceFill(['attempts' => 5, 'archetype_detection_queued_at' => now(), 'submitted_at' => now()])->saveQuietly();

    expect(CanonicalJson::hash($builder->build($match->fresh())))->toBe($before);
});

it('carries null deck references for a limited match', function () {
    $match = syncTestMatch(['format' => 'DZNR', 'deck_version_id' => null]);

    $bundle = app(MatchBundleBuilder::class)->build($match);

    expect($bundle['match']['deck_mtgo_id'])->toBeNull()
        ->and($bundle['match']['deck_version_signature'])->toBeNull();
});

it('emits every datetime as UTC ISO-8601 seconds', function () {
    $match = syncTestMatch();

    $bundle = app(MatchBundleBuilder::class)->build($match);

    expect($bundle['match']['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('gives two leagues sharing a token two distinct client ids', function () {
    [$a, $b] = syncTestLeaguePair(); // same token, different started_at

    $builder = app(LeagueBundleBuilder::class);

    expect($builder->clientId($a))->not->toBe($builder->clientId($b));
});

it('builds a league bundle with the limited fields and no local ids', function () {
    [$league] = syncTestLeaguePair();

    $bundle = app(LeagueBundleBuilder::class)->build($league);

    expect($bundle['league'])->toHaveKeys(['kind', 'set_code', 'mtgo_course_id'])
        ->and($bundle['league'])->not->toHaveKeys(['id', 'deck_version_id', 'event_id']);
});

it('sanitizes the sidecar deck_client_id the same way the deck client id uploads', function () {
    $match = syncTestMatch();
    $match->deckVersion->deck->update(['mtgo_id' => 'limited:791bacca-caea-4d88-b6c7-3bc067d412c2']);

    $sidecar = app(MatchBundleBuilder::class)->sidecar($match->fresh());

    expect($sidecar['deck_client_id'])->toBe('limited_791bacca-caea-4d88-b6c7-3bc067d412c2');
});

it('sanitizes a limited deck mtgo_id into a server-legal client id', function () {
    // Limited decks only reach the dirty/known set on the supporter tier;
    // this test is about client id sanitization, not the tier gate.
    AppSettings::setSyncSlots(['tier' => 'supporter', 'limit' => null, 'used' => 0, 'decks' => []]);

    $deck = syncTestDeck();
    $deck->update(['mtgo_id' => 'limited:791bacca-caea-4d88-b6c7-3bc067d412c2']);
    $deck->refresh();

    $builder = app(DeckBundleBuilder::class);
    $clientId = $builder->clientId($deck);

    // The server's ClientId::RULE; a colon 422s the whole manifest chunk.
    expect($clientId)->toBe('limited_791bacca-caea-4d88-b6c7-3bc067d412c2')
        ->and($clientId)->toMatch('/^[A-Za-z0-9][A-Za-z0-9_-]{0,190}$/')
        ->and(DirtyRows::knownIds('deck'))->toContain($clientId)
        // Identity still round-trips through the bundle body untouched.
        ->and($builder->build($deck)['deck']['mtgo_id'])->toBe('limited:791bacca-caea-4d88-b6c7-3bc067d412c2');
});

it('builds a deck bundle whose versions carry signature and modified_at only', function () {
    $deck = syncTestDeck();

    $bundle = app(DeckBundleBuilder::class)->build($deck);

    expect($bundle['versions'][0])->toHaveKeys(['signature', 'modified_at'])
        ->and($bundle['versions'][0])->not->toHaveKey('cards');
});

it('derives the match sidecar from bundle content with both archetype sides', function () {
    $match = syncTestMatch();

    $sidecar = app(MatchBundleBuilder::class)->sidecar($match);

    expect($sidecar)->toHaveKeys(['occurred_at', 'format', 'result', 'games_won', 'games_lost', 'deck_client_id', 'archetype_uuid', 'opponent_archetype_uuid'])
        ->and($sidecar['archetype_uuid'])->not->toBe($sidecar['opponent_archetype_uuid']);
});

it('orders same-second timelines by content hash, not insertion order or row id', function () {
    $match = syncTestMatch();
    $game = $match->games()->orderBy('mtgo_id')->first();

    GameTimeline::where('game_id', $game->id)->delete();

    $contentA = ['note' => 'alpha-marker'];
    $contentB = ['note' => 'bravo-marker'];
    $hashA = hash('sha256', CanonicalJson::encode($contentA));
    $hashB = hash('sha256', CanonicalJson::encode($contentB));

    // Whichever content hashes lower must be emitted first. Insert it
    // SECOND, so it gets the higher row id, and give both rows the exact
    // same timestamp: an id-based tiebreak would then get the order
    // backwards, and only a content-derived tiebreak gets it right.
    $lowerHash = $hashA < $hashB ? $contentA : $contentB;
    $higherHash = $hashA < $hashB ? $contentB : $contentA;

    $tick = now();
    GameTimeline::create(['game_id' => $game->id, 'timestamp' => $tick, 'content' => $higherHash]);
    GameTimeline::create(['game_id' => $game->id, 'timestamp' => $tick, 'content' => $lowerHash]);

    $bundle = app(MatchBundleBuilder::class)->build($match->fresh());

    $timelinesForGame = collect($bundle['timelines'])->where('game', $game->mtgo_id)->values();

    expect($timelinesForGame)->toHaveCount(2)
        ->and($timelinesForGame[0]['content'])->toBe($lowerHash)
        ->and($timelinesForGame[1]['content'])->toBe($higherHash);
});

it("orders a mirror match's archetypes by player_username when the uuid ties", function () {
    $match = syncTestMatch();
    $mirroredArchetype = Archetype::factory()->create();

    MatchArchetype::where('mtgo_match_id', $match->id)->update(['archetype_id' => $mirroredArchetype->id]);

    $bundle = app(MatchBundleBuilder::class)->build($match->fresh());

    expect($bundle['archetypes'])->toHaveCount(2)
        ->and($bundle['archetypes'][0]['uuid'])->toBe($bundle['archetypes'][1]['uuid'])
        ->and($bundle['archetypes'][0]['player_username'])->toBe('local_player')
        ->and($bundle['archetypes'][1]['player_username'])->toBe('opp_'.$match->token);
});

it('orders deck versions by signature when modified_at ties', function () {
    $deck = Deck::factory()->create();
    $sharedModifiedAt = now();

    DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => $sharedModifiedAt, 'signature' => 'zzz-signature']);
    DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => $sharedModifiedAt, 'signature' => 'aaa-signature']);

    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());

    expect($bundle['versions'])->toHaveCount(2)
        ->and($bundle['versions'][0]['signature'])->toBe('aaa-signature')
        ->and($bundle['versions'][1]['signature'])->toBe('zzz-signature');
});

it('carries the draft, its picks in ordinal order, and deck snapshots in the league bundle', function () {
    [$league] = syncTestLeaguePair();
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 2, 'cards_available' => [7, 8], 'picked_catalog_id' => 8]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'cards_available' => [5, 6], 'picked_catalog_id' => 5]);
    LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'source' => 'registered',
        'cards' => [['catalog_id' => 5, 'quantity' => 1, 'sideboard' => false]],
        'signature' => 'sig-1',
        'captured_at' => now(),
    ]);

    $bundle = app(LeagueBundleBuilder::class)->build($league->fresh());

    expect($bundle['drafts'])->toHaveCount(1)
        ->and($bundle['drafts'][0]['draft_token'])->toBe($draft->draft_token)
        ->and(array_column($bundle['drafts'][0]['picks'], 'ordinal'))->toBe([1, 2])
        ->and($bundle['drafts'][0]['picks'][0]['cards_available'])->toBe([5, 6])
        ->and($bundle['snapshots'])->toHaveCount(1)
        ->and($bundle['snapshots'][0]['signature'])->toBe('sig-1')
        ->and($bundle['drafts'][0])->not->toHaveKeys(['id', 'league_id', 'tournament_id'])
        ->and($bundle['snapshots'][0])->not->toHaveKeys(['id', 'league_id', 'match_id']);

    // Byte-stable across builds, like every other bundle.
    expect(CanonicalJson::hash($bundle))->toBe(CanonicalJson::hash(app(LeagueBundleBuilder::class)->build($league->fresh())));
});

it('carries the manual flag and each player\'s hand-entered opening hand', function () {
    $match = syncTestMatch();
    $match->forceFill(['manual' => true])->saveQuietly();
    $game = $match->games->sortBy('mtgo_id')->first();
    $local = $game->players->first(fn ($p) => $p->pivot->is_local);
    $hand = ['kept' => [101, 102, 103, 104, 105, 106], 'bottomed' => [107], 'mulligans' => [[201, 202, 203, 204, 205, 206, 207]]];

    $game->players()->updateExistingPivot($local->id, ['opening_hand_json' => $hand, 'mulligan_count' => 1, 'starting_hand_size' => 6]);

    $bundle = app(MatchBundleBuilder::class)->build($match->fresh());

    expect($bundle['match']['manual'])->toBeTrue()
        ->and($bundle['players'][0]['username'])->toBe('local_player')
        ->and($bundle['players'][0]['opening_hand_json'])->toBe($hand)
        ->and($bundle['players'][0]['starting_hand_size'])->toBe(6)
        ->and($bundle['players'][1]['opening_hand_json'])->toBeNull();
});

it('carries sideboard guides and matchup notes on the deck bundle, ordered by archetype uuid and free of local ids', function () {
    $deck = syncTestDeck();
    $later = Archetype::factory()->create(['uuid' => 'bbbbbbbb-0000-0000-0000-000000000000']);
    $earlier = Archetype::factory()->create(['uuid' => 'aaaaaaaa-0000-0000-0000-000000000000']);

    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $later->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-zzz', 'quantity' => 2]);
    SideboardGuideCard::factory()->out()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-aaa', 'quantity' => 3]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-bbb', 'quantity' => 1]);
    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $earlier->id]);

    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $later->id, 'body' => 'Board out the fourth removal', 'created_at' => now()->subHour()]);
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $earlier->id, 'body' => 'Keep the counters in', 'created_at' => now()]);

    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());

    expect($bundle['guides'])->toHaveCount(2)
        ->and($bundle['guides'][0]['archetype_uuid'])->toBe($earlier->uuid)
        ->and($bundle['guides'][0]['cards'])->toBe([])
        ->and($bundle['guides'][0])->not->toHaveKeys(['id', 'deck_id', 'archetype_id'])
        ->and($bundle['guides'][1]['cards'])->toBe([
            ['oracle_id' => 'o-bbb', 'direction' => 'in', 'quantity' => 1],
            ['oracle_id' => 'o-zzz', 'direction' => 'in', 'quantity' => 2],
            ['oracle_id' => 'o-aaa', 'direction' => 'out', 'quantity' => 3],
        ])
        ->and($bundle['notes'])->toHaveCount(2)
        ->and($bundle['notes'][0]['archetype_uuid'])->toBe($earlier->uuid)
        ->and($bundle['notes'][0]['body'])->toBe('Keep the counters in')
        ->and($bundle['notes'][0])->toHaveKeys(['archetype_uuid', 'body', 'created_at'])
        ->and($bundle['notes'][1]['body'])->toBe('Board out the fourth removal');
});

it('carries the cover card and deck archetype on the deck bundle as cross-device ids', function () {
    $card = Card::factory()->create(['mtgo_id' => '98765', 'art_crop' => 'https://example.com/a.jpg']);
    $archetype = Archetype::factory()->create();
    $deck = syncTestDeck();
    $deck->update(['cover_id' => $card->id, 'archetype_id' => $archetype->id]);

    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());

    expect($bundle['deck']['cover_mtgo_id'])->toBe('98765')
        ->and($bundle['deck']['archetype_uuid'])->toBe($archetype->uuid)
        ->and($bundle['deck'])->not->toHaveKeys(['cover_id', 'archetype_id']);
});

it('carries the deck client id on the league sidecar', function () {
    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create(['deck_version_id' => $version->id]);

    $sidecar = app(LeagueBundleBuilder::class)->sidecar($league->fresh());

    expect($sidecar['deck_client_id'])->toBe('limited_abc');
});
