<?php

use App\Actions\Sync\ApplyDeckSyncSlots;
use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\SyncRejection;
use App\Services\Sync\DirtyRows;
use App\Services\Sync\LeagueClientId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets deck flags from the server slot list and stores the summary', function () {
    $on = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => false]);
    $cooling = Deck::factory()->create(['mtgo_id' => '222', 'cloud_sync_enabled' => true]);
    $limited = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'cloud_sync_enabled' => false]);
    $other = Deck::factory()->create(['mtgo_id' => '333', 'cloud_sync_enabled' => true]);

    $slots = ['limit' => null, 'used' => 3, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
        ['client_id' => '222', 'enabled_at' => 'x', 'disabled_at' => 'y', 'frees_at' => 'z'],
        ['client_id' => 'limited_abc', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]];

    ApplyDeckSyncSlots::run($slots);

    expect((bool) $on->fresh()->cloud_sync_enabled)->toBeTrue()
        ->and((bool) $cooling->fresh()->cloud_sync_enabled)->toBeFalse()
        ->and((bool) $limited->fresh()->cloud_sync_enabled)->toBeTrue()
        ->and((bool) $other->fresh()->cloud_sync_enabled)->toBeFalse()
        ->and(AppSettings::syncSlots())->toBe($slots);
});

it('clears deck_not_synced rejections for a deck that just became enabled', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => false]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);
    SyncRejection::create(['type' => 'match', 'client_id' => $match->token, 'reason' => 'deck_not_synced', 'hash' => str_repeat('a', 64)]);
    SyncRejection::create(['type' => 'match', 'client_id' => 'unrelated', 'reason' => 'hash_mismatch', 'hash' => str_repeat('b', 64)]);

    ApplyDeckSyncSlots::run(['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]]);

    expect(SyncRejection::where('reason', 'deck_not_synced')->count())->toBe(0)
        ->and(SyncRejection::where('reason', 'hash_mismatch')->count())->toBe(1);
});

it('leaves updated_at alone, so a second apply of the same ledger changes nothing', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => false]);
    $deck->forceFill(['synced_hash' => str_repeat('a', 64), 'synced_at' => now()->addSecond()])->saveQuietly();
    $updatedAt = $deck->fresh()->updated_at;

    $slots = ['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]];

    // Well past the row's own timestamps: a write that touched updated_at
    // would land here and make the deck dirty, pushing it straight back up.
    $this->travelTo(now()->addMinutes(5));

    ApplyDeckSyncSlots::run($slots);
    ApplyDeckSyncSlots::run($slots);

    expect((bool) $deck->fresh()->cloud_sync_enabled)->toBeTrue()
        ->and($deck->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(DirtyRows::query('deck')->whereKey($deck->id)->exists())->toBeFalse();
});

it('lowers the flag on a soft-deleted deck and never raises it, whatever the ledger says', function () {
    // 444 is absent from the ledger; 555 is listed enabled, which happens
    // when the deck's delete never reached the server. Neither may sync:
    // the user deleted them both.
    $deleted = Deck::factory()->create(['mtgo_id' => '444', 'cloud_sync_enabled' => true]);
    $deleted->delete();
    $listed = Deck::factory()->create(['mtgo_id' => '555', 'cloud_sync_enabled' => true]);
    $listed->delete();

    $slots = ['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '555', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]];

    ApplyDeckSyncSlots::run($slots);
    ApplyDeckSyncSlots::run($slots);

    expect((bool) Deck::withTrashed()->findOrFail($deleted->id)->cloud_sync_enabled)->toBeFalse()
        ->and((bool) Deck::withTrashed()->findOrFail($listed->id)->cloud_sync_enabled)->toBeFalse();
});

it('clears rejections parked against an already-enabled deck, and keeps those of a disabled one', function () {
    // No flag transition here: the deck arrives enabled, as it would after a
    // disable and re-enable on another device between two runs.
    $enabled = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => true]);
    $enabledVersion = DeckVersion::factory()->create(['deck_id' => $enabled->id]);
    $enabledMatch = MtgoMatch::factory()->create(['deck_version_id' => $enabledVersion->id]);
    $enabledLeague = League::factory()->create(['deck_version_id' => $enabledVersion->id]);

    $off = Deck::factory()->create(['mtgo_id' => '222', 'cloud_sync_enabled' => false]);
    $offVersion = DeckVersion::factory()->create(['deck_id' => $off->id]);
    $offMatch = MtgoMatch::factory()->create(['deck_version_id' => $offVersion->id]);

    foreach ([
        ['type' => 'match', 'client_id' => $enabledMatch->token],
        ['type' => 'league', 'client_id' => LeagueClientId::for($enabledLeague)],
        ['type' => 'match', 'client_id' => $offMatch->token],
    ] as $index => $rejection) {
        SyncRejection::create([...$rejection, 'reason' => 'deck_not_synced', 'hash' => str_repeat((string) $index, 64)]);
    }

    $slots = ['limit' => 1, 'used' => 1, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]];

    ApplyDeckSyncSlots::run($slots);
    ApplyDeckSyncSlots::run($slots);

    expect(SyncRejection::pluck('client_id')->all())->toBe([$offMatch->token]);
});

it('forgets the synced state of a deck\'s matches and leagues when the deck becomes enabled, so they are offered again', function () {
    $deck = Deck::factory()->create(['mtgo_id' => '111', 'cloud_sync_enabled' => false]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $syncedAt = now()->subDay();
    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);
    $match->forceFill(['synced_hash' => str_repeat('a', 64), 'synced_at' => $syncedAt])->saveQuietly();
    $league = League::factory()->create(['deck_version_id' => $version->id]);
    $league->forceFill(['synced_hash' => str_repeat('b', 64), 'synced_at' => $syncedAt])->saveQuietly();

    $otherVersion = syncEnabledDeckVersion();
    $otherMatch = MtgoMatch::factory()->create(['deck_version_id' => $otherVersion->id]);
    $otherMatch->forceFill(['synced_hash' => str_repeat('c', 64), 'synced_at' => now()->addSecond()])->saveQuietly();

    $matchUpdatedAt = $match->fresh()->updated_at;
    $this->travel(1)->hours();

    ApplyDeckSyncSlots::run(['limit' => null, 'used' => 2, 'decks' => [
        ['client_id' => '111', 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
        ['client_id' => (string) $otherVersion->deck->mtgo_id, 'enabled_at' => 'x', 'disabled_at' => null, 'frees_at' => null],
    ]]);

    expect($match->fresh()->synced_hash)->toBeNull()
        ->and($match->fresh()->synced_at)->toBeNull()
        ->and($match->fresh()->updated_at->eq($matchUpdatedAt))->toBeTrue()
        ->and($league->fresh()->synced_hash)->toBeNull()
        ->and($otherMatch->fresh()->synced_hash)->toBe(str_repeat('c', 64))
        ->and(DirtyRows::query('match')->pluck('id')->all())->toBe([$match->id]);
});
