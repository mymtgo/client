<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Sync\ApplyDeckSyncSlots;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\LinkSyncDeviceJob;
use App\Jobs\RunSyncJob;
use App\Models\Deck;
use App\Models\League;
use App\Models\SyncRejection;
use App\Models\SyncState;
use App\Services\Sync\DirtyRows;
use App\Services\Sync\SyncActivity;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * The Sync section of the settings page: link this device to a MyMTGO
 * account, show status, run a sync now, unlink. Every action
 * returns the same status shape as `show` so the widget can re-render from
 * whatever response it just received instead of making a second request.
 */
class SyncController extends Controller
{
    public function show(SyncTokens $tokens): JsonResponse
    {
        return response()->json($this->status($tokens));
    }

    /**
     * What the server holds versus what this device holds, per type, plus
     * the account's slot ledger, so the card can say how much is waiting in
     * the cloud and how many deck slots are in use. Two authenticated calls
     * per explicit request (mount, manual refresh, sync completion), never
     * from the card's 3s poll.
     *
     * Counting is an approximation on purpose: a server-side edit of a row
     * this device already has is invisible to a count, but new-on-server
     * rows (the cross-device case) are exactly the difference, and the sync
     * run itself still does the precise diff.
     *
     * The slot ledger is not an approximation. It is read here rather than
     * taken from the stored copy because `sync_slots` is only written by a
     * completed sync run, so a device that has linked but not yet synced
     * would otherwise have no slot numbers to show at all. Applying it
     * through {@see ApplyDeckSyncSlots} keeps the server as the authority
     * (spec 2026-09-10, rule 5) and self-heals stale per-deck flags on a
     * plain page load.
     */
    public function cloud(SyncTokens $tokens, SyncApi $api): JsonResponse
    {
        if (! $tokens->linked()) {
            return response()->json(['download' => null, 'slots' => null]);
        }

        try {
            $server = $api->status();
            $slots = $api->deckSlots();
            ApplyDeckSyncSlots::run($slots);
        } catch (\Throwable $e) {
            // Offline mode reaches this as an OfflineModeException from the
            // first request, and it is a user choice rather than a fault, so
            // the whole card simply shows nothing rather than an error.
            Log::info('Sync cloud counts unavailable.', ['error' => $e->getMessage()]);

            return response()->json(['download' => null, 'slots' => null]);
        }

        $local = [
            'match' => count(DirtyRows::knownIds('match')),
            'deck' => count(DirtyRows::knownIds('deck')),
            'league' => count(DirtyRows::knownIds('league')),
        ];

        return response()->json([
            'download' => [
                'match' => max(0, $server['match'] - $local['match']),
                'deck' => max(0, $server['deck'] - $local['deck']),
                'league' => max(0, $server['league'] - $local['league']),
            ],
            'slots' => ['limit' => $slots['limit'] ?? null, 'used' => (int) ($slots['used'] ?? 0)],
        ]);
    }

    public function link(SyncTokens $tokens): JsonResponse
    {
        LinkSyncDeviceJob::dispatch();

        return response()->json($this->status($tokens));
    }

    public function unlink(SyncTokens $tokens): JsonResponse
    {
        $tokens->clear();

        // No account, no slots: flags are the server ledger's mirror and
        // there is no ledger to mirror now. Written through toBase() like
        // ApplyDeckSyncSlots, so updated_at is left alone: bumping it would
        // mark every deck dirty and push the whole shelf again on the first
        // run after a relink.
        AppSettings::setSyncSlots(null);
        Deck::withTrashed()->where('cloud_sync_enabled', true)->toBase()->update(['cloud_sync_enabled' => false]);

        return response()->json($this->status($tokens));
    }

    public function run(SyncTokens $tokens): JsonResponse
    {
        // Guarded so a click during an active run (the job is unique, the
        // dispatch below becomes a no-op) does not wipe that run's feed.
        if (! SyncActivity::isRunning()) {
            SyncActivity::markQueued();
        }

        RunSyncJob::dispatch();

        return response()->json($this->status($tokens));
    }

    /**
     * @return array{
     *     linked: bool,
     *     lastSyncedAt: string|null,
     *     pending: int,
     *     notSynced: int,
     *     pendingByType: array{match: int, deck: int, league: int},
     *     activity: list<string>,
     *     syncing: bool,
     *     slots: array{limit: int|null, used: int}|null,
     *     rejections: int,
     *     lastError: string|null,
     * }
     */
    private function status(SyncTokens $tokens): array
    {
        // DirtyRows::query() is a column comparison (synced_hash/synced_at
        // vs updated_at), not a bundle rebuild, so summing it across the
        // three types on every settings page load is cheap.
        $pendingByType = collect(['match', 'deck', 'league'])
            ->mapWithKeys(fn (string $type) => [$type => DirtyRows::query($type)->count()]);

        $lastSyncedAt = SyncState::query()
            ->whereNotNull('last_synced_at')
            ->orderByDesc('last_synced_at')
            ->value('last_synced_at');

        return [
            'linked' => $tokens->linked(),
            'lastSyncedAt' => $lastSyncedAt,
            'pending' => $pendingByType->sum(),
            'pendingByType' => $pendingByType->all(),
            'activity' => SyncActivity::tail(),
            'syncing' => SyncActivity::isRunning(),
            // The honesty number: decks and leagues deleted locally never
            // become dirty rows again, so they would otherwise vanish from
            // sync without ever being reported as unsynced.
            'notSynced' => Deck::onlyTrashed()->count() + League::onlyTrashed()->count(),
            'slots' => $this->slots(),
            'rejections' => SyncRejection::query()->count(),
            'lastError' => AppSettings::syncLastError(),
        ];
    }

    /**
     * @return array{limit: int|null, used: int}|null
     */
    private function slots(): ?array
    {
        $slots = AppSettings::syncSlots();

        if ($slots === null) {
            return null;
        }

        return ['limit' => $slots['limit'] ?? null, 'used' => (int) ($slots['used'] ?? 0)];
    }
}
