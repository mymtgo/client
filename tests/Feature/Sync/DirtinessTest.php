<?php

use App\Actions\SideboardGuides\SaveSideboardGuideCards;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\SideboardGuide;
use App\Services\Sync\DirtyRows;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks a match dirty when a grandchild timeline row changes', function () {
    $match = MtgoMatch::factory()->create(['deck_version_id' => syncEnabledDeckVersion()->id, 'updated_at' => now()->subDay()]);
    $game = Game::factory()->for($match, 'match')->create();
    $timeline = GameTimeline::create(['game_id' => $game->id, 'timestamp' => now(), 'content' => ['t' => 1]]);

    $match->forceFill(['synced_hash' => str_repeat('a', 64), 'synced_at' => now()])->saveQuietly();

    expect(DirtyRows::query('match')->pluck('id'))->not->toContain($match->id);

    // Datetime columns round to the second; without advancing, the touch and
    // synced_at could land in the same tick and isAfter() would read false.
    $this->travelTo(now()->addSecond());

    $timeline->update(['content' => ['t' => 2]]);

    expect($match->fresh()->updated_at->isAfter($match->fresh()->synced_at))->toBeTrue()
        ->and(DirtyRows::query('match')->pluck('id'))->toContain($match->id);
});

it('treats a never-synced row as dirty', function () {
    $match = MtgoMatch::factory()->create(['deck_version_id' => syncEnabledDeckVersion()->id, 'synced_hash' => null]);

    expect(DirtyRows::query('match')->pluck('id'))->toContain($match->id);
});

it('keeps the dirtiness grouping inside any surrounding constraint', function () {
    // An ungrouped orWhere would escape and return the whole table; prove
    // the scope composes by adding an impossible outer constraint.
    MtgoMatch::factory()->create(['synced_hash' => null]);

    $count = DirtyRows::query('match')->whereRaw('1 = 0')->count();

    expect($count)->toBe(0);
});

it('marks a deck dirty when a version is added and excludes soft-deleted decks', function () {
    $deck = Deck::factory()->create(['updated_at' => now()->subDay()]);
    $deck->forceFill(['synced_hash' => str_repeat('b', 64), 'synced_at' => now()])->saveQuietly();

    // Datetime columns round to the second; without advancing, the touch and
    // synced_at could land in the same tick and the dirty comparison misses it.
    $this->travelTo(now()->addSecond());

    DeckVersion::create(['deck_id' => $deck->id, 'signature' => base64_encode('1:4:false'), 'modified_at' => now()]);

    expect(DirtyRows::query('deck')->pluck('id'))->toContain($deck->id);

    $deck->delete();

    expect(DirtyRows::query('deck')->pluck('id'))->not->toContain($deck->id)
        ->and(DirtyRows::knownIds('deck'))->not->toContain((string) $deck->mtgo_id);
});

it('lists known ids per type from a single column', function () {
    $match = MtgoMatch::factory()->create(['deck_version_id' => syncEnabledDeckVersion()->id]);

    expect(DirtyRows::knownIds('match'))->toContain($match->token);
});

it('marks the league dirty when a draft pick lands or a snapshot is recorded', function () {
    $league = League::factory()->create(['deck_version_id' => syncEnabledDeckVersion()->id]);
    $draft = Draft::factory()->create(['league_id' => $league->id]);
    $league->forceFill(['synced_hash' => str_repeat('a', 64), 'synced_at' => now()->addSecond()])->saveQuietly();

    expect(DirtyRows::query('league')->whereKey($league->id)->exists())->toBeFalse();

    $this->travelTo(now()->addSeconds(2));
    DraftPick::factory()->create(['draft_id' => $draft->id]);

    expect(DirtyRows::query('league')->whereKey($league->id)->exists())->toBeTrue();

    $league->forceFill(['synced_at' => now()->addSecond()])->saveQuietly();
    $this->travelTo(now()->addSeconds(2));
    LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'source' => 'registered',
        'cards' => [],
        'signature' => 'sig-d',
        'captured_at' => now(),
    ]);

    expect(DirtyRows::query('league')->whereKey($league->id)->exists())->toBeTrue();
});

it('marks a match dirty when a hand-entered opening hand lands on a game player pivot', function () {
    $match = MtgoMatch::factory()->create(['deck_version_id' => syncEnabledDeckVersion()->id, 'manual' => true, 'updated_at' => now()->subDay()]);
    $game = Game::factory()->for($match, 'match')->create();
    $player = Player::firstOrCreate(['username' => 'local_player']);
    $game->players()->attach($player->id, ['is_local' => true, 'on_play' => true, 'starting_hand_size' => 7, 'instance_id' => 0, 'mulligan_count' => 0]);

    $match->forceFill(['synced_hash' => str_repeat('a', 64), 'synced_at' => now()])->saveQuietly();

    expect(DirtyRows::query('match')->pluck('id'))->not->toContain($match->id);

    $this->travelTo(now()->addSecond());

    $game->players()->updateExistingPivot($player->id, ['opening_hand_json' => ['kept' => [1, 2, 3, 4, 5, 6, 7], 'bottomed' => [], 'mulligans' => []]]);

    expect(DirtyRows::query('match')->pluck('id'))->toContain($match->id);
});

it('marks a deck dirty when a sideboard plan is saved or a matchup note lands', function () {
    $deck = Deck::factory()->create();
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id]);
    $markSynced = fn () => $deck->forceFill(['synced_hash' => str_repeat('a', 64), 'synced_at' => now()])->saveQuietly();

    $markSynced();
    expect(DirtyRows::query('deck')->pluck('id'))->not->toContain($deck->id);

    $this->travelTo(now()->addSecond());
    SaveSideboardGuideCards::run($guide, [['oracle_id' => 'o-1', 'direction' => 'in', 'quantity' => 2]]);

    expect(DirtyRows::query('deck')->pluck('id'))->toContain($deck->id);

    $markSynced();
    expect(DirtyRows::query('deck')->pluck('id'))->not->toContain($deck->id);

    $this->travelTo(now()->addSecond());
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $guide->archetype_id]);

    expect(DirtyRows::query('deck')->pluck('id'))->toContain($deck->id);
});

it('leaves unfinished matches out of sync until they end', function (string $state) {
    $match = MtgoMatch::factory()->{$state}()->create(['deck_version_id' => syncEnabledDeckVersion()->id, 'synced_hash' => null]);

    expect(DirtyRows::query('match')->pluck('id'))->not->toContain($match->id)
        ->and(DirtyRows::knownIds('match'))->not->toContain($match->token);

    $match->update(['state' => MatchState::Complete]);

    expect(DirtyRows::query('match')->pluck('id'))->toContain($match->id)
        ->and(DirtyRows::knownIds('match'))->toContain($match->token);
})->with(['started', 'inProgress', 'ended']);
