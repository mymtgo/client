<?php

use App\Models\Archetype;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\GameTimeline;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use App\Services\Sync\Bundles\DeckBundleBuilder;
use App\Services\Sync\Bundles\DeckBundleImporter;
use App\Services\Sync\Bundles\LeagueBundleBuilder;
use App\Services\Sync\Bundles\LeagueBundleImporter;
use App\Services\Sync\Bundles\MatchBundleBuilder;
use App\Services\Sync\Bundles\MatchBundleImporter;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Builds a bundle from a real match, wipes the database rows, imports the
 * bundle, and returns the reborn match. The purest statement of what sync
 * is for.
 */
function syncRoundTrip(MtgoMatch $match): MtgoMatch
{
    $builder = app(MatchBundleBuilder::class);
    $bundle = $builder->build($match->fresh());
    $hash = CanonicalJson::hash($bundle);
    $token = $match->token;

    // Also round-trip the deck, since the match references it.
    $deckBundle = null;
    if ($match->deckVersion?->deck) {
        $deckBundle = app(DeckBundleBuilder::class)->build($match->deckVersion->deck);
    }

    $match->games()->each(function ($game) {
        DB::table('game_player')->where('game_id', $game->id)->delete();
        $game->timeline()->delete();
        CardGameStat::where('game_id', $game->id)->delete();
    });
    $match->archetypes()->delete();
    $match->games()->delete();
    $match->delete();
    DB::table('deck_versions')->delete();
    DB::table('decks')->delete();

    if ($deckBundle !== null) {
        app(DeckBundleImporter::class)->import($deckBundle, CanonicalJson::hash($deckBundle));
    }

    app(MatchBundleImporter::class)->import($bundle, $hash);

    return MtgoMatch::where('token', $token)->firstOrFail();
}

it('reproduces the match, its games, players, timelines and card stats', function () {
    $original = syncTestMatch();
    $counts = [
        'games' => $original->games()->count(),
        'timelines' => GameTimeline::count(),
        'stats' => CardGameStat::count(),
        'players' => DB::table('game_player')->count(),
    ];

    $reborn = syncRoundTrip($original);

    expect($reborn->games()->count())->toBe($counts['games'])
        ->and(GameTimeline::count())->toBe($counts['timelines'])
        ->and(CardGameStat::count())->toBe($counts['stats'])
        ->and(DB::table('game_player')->count())->toBe($counts['players'])
        ->and($reborn->synced_at)->not->toBeNull();
});

it('produces an identical bundle from the imported match', function () {
    $original = syncTestMatch();
    $before = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($original->fresh()));

    $reborn = syncRoundTrip($original);

    expect(CanonicalJson::hash(app(MatchBundleBuilder::class)->build($reborn)))->toBe($before);
});

it('fills card_game_stats.deck_version_id from the match despite its absence from the bundle', function () {
    $original = syncTestMatch();

    $reborn = syncRoundTrip($original);

    $versionIds = CardGameStat::query()->distinct()->pluck('deck_version_id');

    expect($versionIds)->toHaveCount(1)
        ->and($versionIds->first())->toBe($reborn->deck_version_id);
});

it('preserves which archetype side is whose', function () {
    $original = syncTestMatch();
    $playerUuid = $original->archetypes()->whereHas('player', fn ($q) => $q->where('is_player', true))->first()->archetype->uuid;

    $reborn = syncRoundTrip($original);
    $rebornPlayerUuid = $reborn->archetypes()->whereHas('player', fn ($q) => $q->where('is_player', true))->first()->archetype->uuid;

    expect($rebornPlayerUuid)->toBe($playerUuid);
});

it('restores a match onto version two of a deck, not version one', function () {
    $match = syncTestMatch();
    // Give the deck a second version and point the match at it before building.
    $deck = $match->deckVersion->deck;
    $v2 = $deck->versions()->create(['signature' => base64_encode('9999:4:false'), 'modified_at' => now()]);
    $match->forceFill(['deck_version_id' => $v2->id])->saveQuietly();

    $reborn = syncRoundTrip($match->fresh());

    expect($reborn->deckVersion->signature)->toBe($v2->signature);
});

it('imports without firing model events or touches', function () {
    $original = syncTestMatch();
    $bundle = app(MatchBundleBuilder::class)->build($original->fresh());
    $hash = CanonicalJson::hash($bundle);
    // game_player and game_timelines have no cascade on games.id, so they
    // must go before the games themselves (card_game_stats does cascade).
    $original->games()->each(function ($game) {
        DB::table('game_player')->where('game_id', $game->id)->delete();
        $game->timeline()->delete();
    });
    $original->games()->delete();
    $original->archetypes()->delete();
    $original->delete();

    $touched = false;
    MtgoMatch::updated(function () use (&$touched) {
        $touched = true;
    });

    app(MatchBundleImporter::class)->import($bundle, $hash);

    expect($touched)->toBeFalse();
});

it('imports a limited match with no deck reference', function () {
    $original = syncTestMatch(['format' => 'DZNR', 'deck_version_id' => null]);

    $reborn = syncRoundTrip($original);

    expect($reborn->deck_version_id)->toBeNull()
        ->and($reborn->isLimitedFormat())->toBeTrue();
});

it('recovers each player\'s real instance id from the timeline instead of a shared sentinel', function () {
    $original = syncTestMatch();
    $game = $original->games()->orderBy('mtgo_id')->first();
    $localPlayer = $game->players()->wherePivot('is_local', true)->first();
    $opponentPlayer = $game->players()->wherePivot('is_local', false)->first();

    // Replace this game's timeline with content shaped like the real MTGO
    // state events CreateGames reads: a Players array of {Name, Id} pairs,
    // the same one game_player.instance_id is set from live.
    GameTimeline::where('game_id', $game->id)->delete();
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now(),
        'content' => [
            'Players' => [
                ['Name' => $localPlayer->username, 'Id' => 7],
                ['Name' => $opponentPlayer->username, 'Id' => 9],
            ],
        ],
    ]);

    $reborn = syncRoundTrip($original->fresh());
    $rebornGame = $reborn->games()->where('mtgo_id', $game->mtgo_id)->first();

    $rebornLocal = $rebornGame->players()->wherePivot('is_local', true)->first();
    $rebornOpponent = $rebornGame->players()->wherePivot('is_local', false)->first();

    expect((int) $rebornLocal->pivot->instance_id)->toBe(7)
        ->and((int) $rebornOpponent->pivot->instance_id)->toBe(9);
});

it('marks an imported complete match as submitted so the stats pipeline does not re-report it', function () {
    $reborn = syncRoundTrip(syncTestMatch());

    expect($reborn->submitted_at)->not->toBeNull()
        ->and(MtgoMatch::submittable()->whereKey($reborn->id)->exists())->toBeFalse();
});

it('round-trips a draft league with picks and snapshots, re-linking the snapshot to its match', function () {
    $match = syncTestMatch();
    $league = League::factory()->create(['token' => (string) Str::uuid(), 'started_at' => now()]);
    $match->update(['league_id' => $league->id]);

    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'picks_expected' => 2]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'cards_available' => [5, 6], 'picked_catalog_id' => 5, 'note' => 'first']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 2, 'cards_available' => [7, 8], 'picked_catalog_id' => 8]);
    LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'match_id' => $match->id,
        'match_token' => $match->token,
        'source' => 'registered',
        'cards' => [['catalog_id' => 5, 'quantity' => 1, 'sideboard' => false]],
        'signature' => 'sig-rt',
        'captured_at' => now(),
    ]);

    $builder = app(LeagueBundleBuilder::class);
    $league = $league->fresh();
    $bundle = $builder->build($league);
    $hash = CanonicalJson::hash($bundle);

    // Wipe the league world; the match is deleted too, so the snapshot must
    // come back UNLINKED and get re-linked by the match import afterwards.
    $matchBundle = app(MatchBundleBuilder::class)->build($match->fresh());
    $matchHash = CanonicalJson::hash($matchBundle);
    wipeMatchLocally($match);
    DB::table('draft_picks')->delete();
    DB::table('drafts')->delete();
    DB::table('limited_deck_snapshots')->delete();
    $league->forceDelete();

    app(LeagueBundleImporter::class)->import($bundle, $hash);

    $reborn = League::where('token', $league->token)->firstOrFail();
    $rebornDraft = $reborn->draft;
    $snapshot = $reborn->deckSnapshots()->first();

    expect($rebornDraft->draft_token)->toBe($draft->draft_token)
        ->and($rebornDraft->picks()->orderBy('ordinal')->pluck('note')->first())->toBe('first')
        ->and($rebornDraft->picks()->count())->toBe(2)
        ->and($rebornDraft->picks()->orderBy('ordinal')->first()->cards_available)->toBe([5, 6])
        ->and($snapshot->cards)->toBe([['catalog_id' => 5, 'quantity' => 1, 'sideboard' => false]])
        ->and($snapshot->match_id)->toBeNull();

    app(MatchBundleImporter::class)->import($matchBundle, $matchHash);

    expect($snapshot->fresh()->match_id)->toBe(MtgoMatch::where('token', $match->token)->value('id'));
});

it('imports a pre-amendment league bundle that carries no child keys', function () {
    [$league] = syncTestLeaguePair();
    $bundle = app(LeagueBundleBuilder::class)->build($league);
    unset($bundle['drafts'], $bundle['snapshots']);
    $hash = CanonicalJson::hash($bundle);
    $league->forceDelete();

    app(LeagueBundleImporter::class)->import($bundle, $hash);

    expect(League::where('token', $league->token)->exists())->toBeTrue();
});

it('round-trips a manual match with its hand-entered opening hand', function () {
    $original = syncTestMatch();
    $original->forceFill(['manual' => true])->saveQuietly();
    $game = $original->games->sortBy('mtgo_id')->first();
    $local = $game->players->first(fn ($p) => $p->pivot->is_local);
    $hand = ['kept' => [101, 102, 103, 104, 105, 106], 'bottomed' => [107], 'mulligans' => [[201, 202, 203, 204, 205, 206, 207]]];
    $game->players()->updateExistingPivot($local->id, ['opening_hand_json' => $hand, 'mulligan_count' => 1, 'starting_hand_size' => 6]);
    $before = CanonicalJson::hash(app(MatchBundleBuilder::class)->build($original->fresh()));

    $reborn = syncRoundTrip($original->fresh());

    $rebornGame = $reborn->games->sortBy('mtgo_id')->first();
    $rebornLocal = $rebornGame->players->first(fn ($p) => $p->pivot->is_local);

    expect($reborn->manual)->toBeTrue()
        ->and($rebornLocal->pivot->opening_hand_json)->toBe($hand)
        ->and($rebornLocal->pivot->mulligan_count)->toBe(1)
        ->and(CanonicalJson::hash(app(MatchBundleBuilder::class)->build($reborn)))->toBe($before);
});

it('imports a pre-version-3 match bundle that carries no manual flag or opening hands', function () {
    $original = syncTestMatch();
    $bundle = app(MatchBundleBuilder::class)->build($original);
    unset($bundle['match']['manual']);
    foreach ($bundle['players'] as &$player) {
        unset($player['opening_hand_json']);
    }
    unset($player);
    $hash = CanonicalJson::hash($bundle);
    $token = $original->token;
    $original->games()->each(function ($game) {
        DB::table('game_player')->where('game_id', $game->id)->delete();
        $game->timeline()->delete();
        CardGameStat::where('game_id', $game->id)->delete();
    });
    $original->archetypes()->delete();
    $original->games()->delete();
    $original->delete();

    app(MatchBundleImporter::class)->import($bundle, $hash);

    $reborn = MtgoMatch::where('token', $token)->firstOrFail();

    expect($reborn->manual)->toBeFalse()
        ->and(DB::table('game_player')->whereNotNull('opening_hand_json')->count())->toBe(0)
        ->and(DB::table('game_player')->count())->toBe(4);
});

/**
 * A deck with one sideboard guide (two cards) and one matchup note, plus the
 * bundle built from it. The returned wipe closure removes every local row so
 * the import starts from nothing.
 *
 * @return array{0: Deck, 1: Archetype, 2: array<string, mixed>, 3: Closure}
 */
function syncTestGuidedDeck(): array
{
    $deck = syncTestDeck();
    $archetype = Archetype::factory()->create();
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-in', 'quantity' => 2]);
    SideboardGuideCard::factory()->out()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-out', 'quantity' => 2]);
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id, 'body' => 'Mull aggressively', 'created_at' => now()->subDay()]);

    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());

    $wipe = function () use ($deck): void {
        DB::table('sideboard_guide_cards')->delete();
        DB::table('sideboard_guides')->delete();
        DB::table('deck_archetype_notes')->delete();
        DB::table('deck_versions')->delete();
        $deck->forceDelete();
    };

    return [$deck, $archetype, $bundle, $wipe];
}

it('round-trips a deck with its sideboard guide and matchup note, replacing rather than duplicating them', function () {
    [$deck, $archetype, $bundle, $wipe] = syncTestGuidedDeck();
    $hash = CanonicalJson::hash($bundle);
    $authoredAt = DeckArchetypeNote::first()->created_at->getTimestamp();
    $wipe();

    app(DeckBundleImporter::class)->import($bundle, $hash);
    app(DeckBundleImporter::class)->import($bundle, $hash);

    $reborn = Deck::where('mtgo_id', $deck->mtgo_id)->firstOrFail();
    $guide = SideboardGuide::firstOrFail();

    expect(SideboardGuide::count())->toBe(1)
        ->and($guide->deck_id)->toBe($reborn->id)
        ->and($guide->archetype_id)->toBe($archetype->id)
        ->and(SideboardGuideCard::count())->toBe(2)
        ->and(DeckArchetypeNote::count())->toBe(1)
        ->and(DeckArchetypeNote::first()->created_at->getTimestamp())->toBe($authoredAt)
        ->and(CanonicalJson::hash(app(DeckBundleBuilder::class)->build($reborn)))->toBe($hash);
});

it('stubs an archetype the local cache lacks for a synced guide, as a match import does', function () {
    [$deck, $archetype, $bundle, $wipe] = syncTestGuidedDeck();
    $uuid = $archetype->uuid;
    $wipe();
    $archetype->delete();

    app(DeckBundleImporter::class)->import($bundle, CanonicalJson::hash($bundle));

    $stub = Archetype::where('uuid', $uuid)->firstOrFail();

    expect($stub->name)->toBe($uuid)
        ->and(SideboardGuide::firstOrFail()->archetype_id)->toBe($stub->id)
        ->and(DeckArchetypeNote::firstOrFail()->archetype_id)->toBe($stub->id);
});

it('leaves local guides and notes alone when a pre-version-3 deck bundle carries no guide keys', function () {
    [$deck, $archetype, $bundle] = syncTestGuidedDeck();
    unset($bundle['guides'], $bundle['notes']);

    app(DeckBundleImporter::class)->import($bundle, CanonicalJson::hash($bundle));

    expect(SideboardGuide::count())->toBe(1)
        ->and(SideboardGuideCard::count())->toBe(2)
        ->and(DeckArchetypeNote::count())->toBe(1)
        ->and($deck->fresh()->synced_hash)->not->toBeNull();
});

it('restores cover and archetype from a deck bundle', function () {
    $card = Card::factory()->create(['mtgo_id' => '98765', 'art_crop' => 'https://example.com/a.jpg']);
    $deck = syncTestDeck();
    $deck->update(['cover_id' => $card->id]);
    $bundle = app(DeckBundleBuilder::class)->build($deck->fresh());
    $bundle['deck']['archetype_uuid'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    // deck_versions.deck_id has no cascade, so it must go before the deck
    // itself, the same order syncRoundTrip and syncTestGuidedDeck's wipe use.
    DB::table('deck_versions')->where('deck_id', $deck->id)->delete();
    $deck->forceDelete();

    app(DeckBundleImporter::class)->import($bundle, CanonicalJson::hash($bundle));

    $restored = Deck::where('mtgo_id', $bundle['deck']['mtgo_id'])->sole();

    expect($restored->cover_id)->toBe($card->id)
        ->and($restored->archetype->uuid)->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});
