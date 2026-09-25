<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Matches\DetermineMatchDeck;
use App\Actions\Matches\ResolveMatchDeckFromSidecar;
use App\Actions\Sidecar\ApplySidecarProjection;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\GameFieldDiff;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\SidecarSnapshotFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-deck-'.uniqid();
    mkdir($this->dir);
    AppSettings::setSidecarDirectory($this->dir);
    SidecarAuthorityFlags::applyRemote(['match_deck' => true]);

    $account = Account::create(['username' => 'local.player', 'active' => true, 'tracked' => true]);
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'o-1001']);
    Card::factory()->create(['mtgo_id' => 1003, 'oracle_id' => 'o-1003']);
    $this->deck = Deck::factory()->create(['account_id' => $account->id, 'mtgo_id' => '12345']);
    $this->v1 = DeckVersion::factory()->create(['deck_id' => $this->deck->id, 'signature' => GenerateDeckSignature::run(collect([
        ['mtgo_id' => 1001, 'quantity' => 3, 'sideboard' => 'false'],
    ]))]);
    $this->v2 = DeckVersion::factory()->create(['deck_id' => $this->deck->id, 'signature' => GenerateDeckSignature::run(collect([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1003, 'quantity' => 2, 'sideboard' => 'true'],
    ]))]);
    $this->match = MtgoMatch::factory()->create(['state' => MatchState::InProgress, 'format' => 'CMODERN', 'deck_version_id' => null, 'started_at' => now()]);
});

it('links the registered deck by NetDeckId and signature', function () {
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    DetermineMatchDeck::run($this->match);

    expect($this->match->fresh()->deck_version_id)->toBe($this->v2->id);
});

it('waits for the snapshot while the sidecar is live, then falls back to the log', function () {
    SidecarSnapshotFactory::attachedStatus($this->dir);

    DetermineMatchDeck::run($this->match);
    expect($this->match->fresh()->deck_version_id)->toBeNull();

    $this->travel(6)->seconds();
    DetermineMatchDeck::run($this->match);
    // no games and no deck_used events: the log path stops before lookup, as today
    expect($this->match->fresh()->deck_version_id)->toBeNull();
});

it('decides as soon as the snapshot lands, without waiting out the window', function () {
    SidecarSnapshotFactory::attachedStatus($this->dir);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    DetermineMatchDeck::run($this->match);

    expect($this->match->fresh()->deck_version_id)->toBe($this->v2->id);
});

it('corrects a log-linked version of the same deck and moves the league with it', function () {
    $league = League::factory()->create(['deck_version_id' => $this->v1->id]);
    $this->match->update(['deck_version_id' => $this->v1->id, 'league_id' => $league->id]);
    SidecarSnapshotFactory::matchStarted($this->match);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    ApplySidecarProjection::run($this->match->fresh());

    expect($this->match->fresh()->deck_version_id)->toBe($this->v2->id)
        ->and($league->fresh()->deck_version_id)->toBe($this->v2->id)
        ->and(GameFieldDiff::where('field', 'match_deck')->exists())->toBeTrue();
});

it('correcting twice is a no-op', function () {
    $this->match->update(['deck_version_id' => $this->v1->id]);
    SidecarSnapshotFactory::matchStarted($this->match);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    ApplySidecarProjection::run($this->match->fresh());
    ApplySidecarProjection::run($this->match->fresh());

    expect($this->match->fresh()->deck_version_id)->toBe($this->v2->id);
});

it('never replaces a link to a different deck', function () {
    $otherDeck = DeckVersion::factory()->create();
    $this->match->update(['deck_version_id' => $otherDeck->id]);
    SidecarSnapshotFactory::matchStarted($this->match);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    ApplySidecarProjection::run($this->match->fresh());

    expect($this->match->fresh()->deck_version_id)->toBe($otherDeck->id);
});

it('never touches a submitted, manual or limited match', function (array $attrs) {
    $this->match->update($attrs);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    DetermineMatchDeck::run($this->match->fresh());

    expect($this->match->fresh()->deck_version_id)->toBeNull();
})->with([
    'submitted' => [['submitted_at' => now()]],
    'manual' => [['manual' => true]],
    'limited' => [['format' => 'DMKM']],
]);

it('ignores an unknown NetDeckId or an unreadable item list', function (array $deck) {
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck($deck));

    DetermineMatchDeck::run($this->match);

    expect($this->match->fresh()->deck_version_id)->toBeNull();
})->with([
    'unknown deck' => [['net_deck_id' => 99999]],
    'no items' => [['items' => null]],
]);

it('does nothing with the flag off', function () {
    SidecarAuthorityFlags::applyRemote(['match_deck' => false]);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    DetermineMatchDeck::run($this->match);

    expect($this->match->fresh()->deck_version_id)->toBeNull();
});

it('does not rewrite the deck of a manual league', function () {
    $league = League::factory()->manual()->create(['deck_version_id' => $this->v1->id]);
    $this->match->update(['deck_version_id' => $this->v1->id, 'league_id' => $league->id]);
    SidecarSnapshotFactory::matchStarted($this->match);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    ApplySidecarProjection::run($this->match->fresh());

    expect($league->fresh()->deck_version_id)->toBe($this->v1->id);
});

it('keeps the deck correction diff after the next pass', function () {
    $this->match->update(['deck_version_id' => $this->v1->id]);
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: SidecarSnapshotFactory::deck());

    ResolveMatchDeckFromSidecar::run($this->match->fresh());
    ResolveMatchDeckFromSidecar::run($this->match->fresh());

    expect(GameFieldDiff::where('field', 'match_deck')->exists())->toBeTrue();
});

it('treats a non-array registered deck as missing', function () {
    SidecarSnapshotFactory::snapshot($this->match, 'started', deck: null)->update(['data' => ['phase' => 'started', 'registered_deck' => 'garbage']]);

    DetermineMatchDeck::run($this->match);

    expect($this->match->fresh()->deck_version_id)->toBeNull();
});
