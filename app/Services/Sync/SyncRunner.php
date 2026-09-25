<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Actions\Cards\CreateMissingCardsFromLocalData;
use App\Actions\Sync\ApplyDeckSyncSlots;
use App\Actions\Sync\AttestKnownAccounts;
use App\Events\AppNotification;
use App\Exceptions\OfflineModeException;
use App\Exceptions\Sync\NotLinkedException;
use App\Facades\AppSettings;
use App\Managers\MtgoManager;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\SyncRejection;
use App\Models\SyncState;
use App\Services\Sync\Bundles\DeckBundleBuilder;
use App\Services\Sync\Bundles\DeckBundleImporter;
use App\Services\Sync\Bundles\LeagueBundleBuilder;
use App\Services\Sync\Bundles\LeagueBundleImporter;
use App\Services\Sync\Bundles\MatchBundleBuilder;
use App\Services\Sync\Bundles\MatchBundleImporter;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sync run itself: manifest, push, pull, per type, in the fixed order
 * deck, league, match. Every type gets its own manifest call even when
 * nothing local is dirty, so `sync_state` always advances.
 *
 * `NotLinkedException` anywhere aborts the whole run silently (the settings
 * UI reads `SyncTokens::linked()` to drive relink prompts). `OfflineModeException`
 * (thrown by the offline-aware HTTP client the moment a request would leave
 * the device) aborts the run the same way, logged at info rather than error:
 * offline mode is a user choice, not a fault, and belongs alongside the
 * schedule's own offline gate (see {@see MtgoManager::schedule()})
 * as belt and braces for a run already queued when the user goes offline.
 * Any other throwable aborts the run, logs at error, and leaves state
 * consistent: every step here is idempotent, so the next run simply
 * re-diffs.
 *
 * `--full` (plumbed in as $full) and a `sync_state.canonical_version`
 * mismatch both collapse onto the same $effectiveFull flag for a type (see
 * {@see runType}): `since` is forced to null, the candidate row set widens
 * from `DirtyRows::query()` to every row of that type, and the manifest
 * scan's self-heal shortcut is bypassed so every row's hash is rebuilt and
 * sent even when it already equals `synced_hash` (a version bump can leave
 * a stale hash that still happens to match the freshly rebuilt one under
 * the old shape; skipping that row would leave the server holding the old
 * bytes forever). Both the manifest scan and the push both resume from
 * `sync_state.full_cursor` (an `id > full_cursor` filter, rows visited in
 * id order) so a killed full run picks back up rather than restarting from
 * scratch; the cursor advances after each pushed batch and is cleared once
 * the type's full run completes.
 */
class SyncRunner
{
    /**
     * @var list<string>
     */
    private const TYPES = ['deck', 'league', 'match'];

    public function __construct(
        private readonly SyncApi $api,
        private readonly DeckBundleBuilder $deckBuilder,
        private readonly DeckBundleImporter $deckImporter,
        private readonly LeagueBundleBuilder $leagueBuilder,
        private readonly LeagueBundleImporter $leagueImporter,
        private readonly MatchBundleBuilder $matchBuilder,
        private readonly MatchBundleImporter $matchImporter,
    ) {}

    private int $pushedMatches = 0;

    private int $pulledMatches = 0;

    /** Whether this run wrote at least one bundle into the local database. */
    private bool $importedAnything = false;

    private bool $trashedSlotsReleased = false;

    public function run(bool $full = false): void
    {
        $this->pushedMatches = 0;
        $this->pulledMatches = 0;
        $this->importedAnything = false;
        $this->trashedSlotsReleased = false;

        SyncActivity::reset();
        SyncActivity::log($full ? 'Sync started (full reconcile).' : 'Sync started.');

        try {
            foreach (self::TYPES as $type) {
                $this->runType($type, $full);
            }

            $this->backfillCardsForImportedRows();
            $this->attestKnownAccounts();

            AppSettings::setSyncLastError(null);
            SyncActivity::log('Sync complete.');
            $this->notifyCompletion();
        } catch (NotLinkedException) {
            SyncActivity::log('Aborted: this device is not linked.');
            Log::info('Sync run aborted: this device is not linked.');
        } catch (OfflineModeException) {
            // Belt and braces alongside the schedule gate in
            // MtgoManager::schedule(): a run already queued (or a manual
            // "Sync now") when the user flips offline mode on mid-flight
            // still hits this, and offline mode is a user choice, not a
            // fault, so it must not log as an error every half hour.
            SyncActivity::log('Aborted: offline mode is enabled.');
            Log::info('Sync run aborted: offline mode is enabled.');
        } catch (Throwable $e) {
            AppSettings::setSyncLastError($e->getMessage());
            SyncActivity::log('Aborted: '.$e->getMessage());

            Log::error('Sync run aborted due to an unexpected error.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Re-states which MTGO logins this client has seen, every run.
     *
     * Attesting used to happen only when the device linked and when offline
     * mode was switched off. Both are single shots, and the link one fires a
     * second before the first sync, which is exactly when SQLite is busiest:
     * a "database is locked" there lost the attestation for good, leaving the
     * website with matches synced but no linked player. Doing it here instead
     * means any missed attestation heals on the next run.
     *
     * Never fatal: the run's own work is already done by this point, and the
     * API side is idempotent, so a failure is logged and left for next run.
     */
    private function attestKnownAccounts(): void
    {
        try {
            ['confirmed' => $confirmed, 'owed' => $owed] = AttestKnownAccounts::run();

            if ($confirmed > 0) {
                SyncActivity::log(sprintf('Confirmed %d MTGO account%s.', $confirmed, $confirmed === 1 ? '' : 's'));
            }

            if ($owed > 0) {
                SyncActivity::log(sprintf('Could not confirm %d MTGO account%s, retrying next run.', $owed, $owed === 1 ? '' : 's'));
            }
        } catch (Throwable $e) {
            SyncActivity::log('Could not confirm your MTGO account, retrying next run.');

            Log::info('Sync run could not attest known accounts; retrying next run.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function runType(string $type, bool $full): void
    {
        $state = SyncState::firstOrNew(['type' => $type]);
        $configVersion = (int) config('sync_client.canonical_version');
        $versionMismatch = $state->exists && (int) $state->canonical_version !== $configVersion;

        // A canonical_version mismatch forces full mode for this type on
        // its own, no --full flag required: it folds into the same
        // $effectiveFull flag the rest of this method (and manifestPhase,
        // pushPhase, candidateQuery) key off, so it gets the same
        // since=null, full candidate set, self-heal bypass, and resumable
        // cursor a --full run gets.
        $effectiveFull = $full || $versionMismatch;

        $since = $effectiveFull ? null : self::datetime($state->last_synced_at);
        $cursor = $effectiveFull && $state->full_cursor !== null ? (int) $state->full_cursor : null;

        // Captured before the manifest goes out: an edit landing mid-run is
        // still older than this timestamp, so it is re-checked next run
        // rather than silently missed.
        $startedAt = now();

        $manifest = $this->manifestPhase($type, $since, $effectiveFull, $cursor);

        // Every manifest carries the slot ledger, and deck is the first type
        // in TYPES, so the league and match phases of this same run already
        // see the flags the server just confirmed.
        //
        // The guard is on `decks`, not on `slots`: an empty decks list is a
        // real all-off ledger and must be applied, but a payload with no
        // decks key at all is malformed, and applying it would gate every
        // match and league out of sync until the next good manifest. Leave
        // the flags and the stored ledger exactly as they were instead.
        if (is_array($manifest['slots']['decks'] ?? null)) {
            ApplyDeckSyncSlots::run($manifest['slots']);
            $this->releaseTrashedDeckSlots($manifest['slots']);
        }

        if ($state->canonical_version === null) {
            $state->canonical_version = $configVersion;
        }
        $state->save();

        SyncActivity::log(sprintf(
            '%s: %d to upload, %d to download.',
            $type,
            count($manifest['upload']),
            count($manifest['download']),
        ));

        $this->pushPhase($type, $manifest['upload'], $manifest['hashes'], $state, $effectiveFull, $cursor);

        $this->pullPhase($type, $manifest['download']);

        $state->last_synced_at = $startedAt;
        $state->canonical_version = $configVersion;

        // The type made it all the way through this run without dying
        // partway, so nothing is left to resume: a future full run (another
        // --full, or a later version bump) starts clean rather than
        // skipping rows this run already covered.
        if ($effectiveFull) {
            $state->full_cursor = null;
        }
        $state->save();
    }

    /**
     * Hands back the slot of any deck the user deleted locally while its
     * disable never reached the server: DestroyController swallows that
     * failure by design, so the ledger keeps listing the deck enabled. Left
     * alone it holds a free account's only slot forever, since the toggle
     * route refuses a trashed deck and the settings UI cannot even name it.
     *
     * Once per run, not per type: the first type whose manifest carries a
     * ledger does the work and the rest skip it.
     *
     * Failures are logged and skipped one deck at a time. A slot that could
     * not be released now is retried next run, and must never abort a sync
     * that is otherwise healthy.
     *
     * @param  array<string, mixed>  $slots
     */
    private function releaseTrashedDeckSlots(array $slots): void
    {
        if ($this->trashedSlotsReleased) {
            return;
        }

        $this->trashedSlotsReleased = true;

        $enabledClientIds = collect($slots['decks'] ?? [])
            ->filter(fn (array $deck) => ($deck['disabled_at'] ?? null) === null)
            ->map(fn (array $deck) => (string) $deck['client_id'])
            ->all();

        if ($enabledClientIds === []) {
            return;
        }

        $stranded = Deck::onlyTrashed()
            ->pluck('mtgo_id')
            ->map(fn ($mtgoId) => DeckClientId::for((string) $mtgoId))
            ->intersect($enabledClientIds);

        foreach ($stranded as $clientId) {
            try {
                $summary = $this->api->setDeckSync($clientId, false);

                if (is_array($summary['decks'] ?? null)) {
                    ApplyDeckSyncSlots::run($summary);
                }
            } catch (Throwable $e) {
                Log::info('Could not release the cloud sync slot of a deleted deck.', [
                    'client_id' => $clientId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Scans the candidate rows for $type, self-healing any whose rebuilt
     * hash already matches synced_hash (a $touches cascade with no content
     * change) before it ever counts as dirty, then chunks the remaining
     * dirty client ids at manifest_chunk. Every chunked request before the
     * last carries known: [] and last: false; a final, always-separate
     * request carries the complete known id list and last: true, even when
     * there were zero dirty rows to chunk in the first place.
     *
     * In full mode the self-heal shortcut is bypassed entirely: every
     * candidate row is treated as dirty and its hash rebuilt and queued,
     * synced_hash notwithstanding. A true reconcile exists to get every
     * row's hash back in front of the server, including a row whose
     * synced_hash happens to still equal the freshly rebuilt one (self-heal
     * would otherwise skip exactly that row).
     *
     * $cursor (only meaningful in full mode) is the id the previous,
     * interrupted full run for this type already pushed past; rows at or
     * below it are skipped here the same way they are in pushPhase, since
     * they were already classified this reconcile cycle.
     *
     * The candidate query is eager-loaded with the type's builder relations
     * (see {@see candidateQuery}) so this scan costs roughly one query per
     * relation per lazyById chunk, not one per relation per row; the
     * builder's own loadMissing() then finds everything already loaded and
     * issues nothing further.
     *
     * @return array{upload: list<string>, download: list<string>, hashes: array<string, array{hash: string, updated_at: ?string}>, slots: array<string, mixed>|null}
     */
    private function manifestPhase(string $type, ?string $since, bool $full, ?int $cursor): array
    {
        $chunkSize = max(1, (int) config('sync_client.limits.manifest_chunk'));

        $dirtyEntries = [];
        $hashes = [];

        foreach ($this->candidateQuery($type, $full, $cursor)->lazyById(25) as $row) {
            $bundle = $this->build($type, $row);
            $hash = CanonicalJson::hash($bundle);

            if (! $full && $row->synced_hash !== null && hash_equals($row->synced_hash, $hash)) {
                $this->markClean($row::class, $row->getKey(), $row->updated_at);

                continue;
            }

            $clientId = $this->clientId($type, $row);
            $updatedAt = self::datetime($row->updated_at);
            // The server's ManifestRequest requires each dirty entry as an
            // object (client_id, hash, updated_at), not a bare client_id:
            // ManifestDiff needs the hash to know whether the row actually
            // changed and updated_at to break a same-hash tie, so a bare
            // string here would leave every row silently unresolved.
            $dirtyEntries[] = ['client_id' => $clientId, 'hash' => $hash, 'updated_at' => $updatedAt];
            // Hashes only, never the bundle itself: this map lives for the
            // rest of the run (through the push phase), so it must stay
            // cheap regardless of how many rows are dirty.
            $hashes[$clientId] = ['hash' => $hash, 'updated_at' => $updatedAt];
        }

        $upload = [];

        foreach (array_chunk($dirtyEntries, $chunkSize) as $chunk) {
            $response = $this->api->manifest($type, $since, [], $chunk, false);
            $upload = [...$upload, ...($response['upload'] ?? [])];
        }

        $final = $this->api->manifest($type, $since, DirtyRows::knownIds($type), [], true);
        $upload = array_values(array_unique([...$upload, ...($final['upload'] ?? [])]));

        return [
            'upload' => $upload,
            'download' => $final['download'] ?? [],
            'hashes' => $hashes,
            'slots' => $final['slots'] ?? null,
        ];
    }

    /**
     * For each id the manifest asked to be uploaded: skip it when a stored
     * rejection carries the exact hash we would send again (rejected once,
     * unchanged since, not retried), gzip and pack the rest greedily into
     * requests bounded by compressed_per_request/blobs_per_request, and set
     * one aside as a local too_large_local rejection when it alone exceeds
     * compressed_per_blob.
     *
     * Match rows push oldest-first by started_at, via
     * {@see pushCandidateRows} rather than an orderBy() bolted onto the
     * paginated query itself: Laravel's forPageAfterId (what lazyById pages
     * on) only strips id-column orders, so an orderBy('started_at') applied
     * directly to that query still pages "WHERE id > lastId" underneath a
     * started_at-sorted result set. A dirty row with a lower id than a page
     * boundary would then be skipped forever past the first page, and rows
     * straddling a boundary upload twice. Full mode skips the reordering
     * and pushes in plain id order instead, which is what makes the cursor
     * below a meaningful resume point.
     *
     * $hashes carries clientId => {hash, updated_at} forward from the
     * manifest scan (see manifestPhase) so a row whose content has not
     * moved since that scan is not rehashed here.
     *
     * $sent (built fresh per batch, not for the whole method) holds only
     * the scalars {@see flushPushBatch} needs to mark a stored or rejected
     * row afterwards, never the loaded model or its eager-loaded relations:
     * it is discarded the moment its batch flushes, so nothing here grows
     * with how many rows the whole phase pushes, only with one batch's
     * worth.
     *
     * In full mode, $cursor resumes the scan past whatever an earlier,
     * interrupted full run for this type already pushed, and $state's
     * full_cursor advances after each flushed batch to the highest id in
     * that batch: a run killed partway through leaves the cursor at the
     * last batch it actually completed, so the next full run for this type
     * (another --full, or the version mismatch still standing) resumes
     * there rather than re-pushing everything from the start.
     *
     * @param  list<string>  $uploadIds
     * @param  array<string, array{hash: string, updated_at: ?string}>  $hashes
     */
    private function pushPhase(string $type, array $uploadIds, array $hashes, SyncState $state, bool $full, ?int $cursor): void
    {
        if ($uploadIds === []) {
            return;
        }

        $uploadSet = array_flip($uploadIds);
        $limits = config('sync_client.limits');

        $batch = [];
        $batchBytes = 0;
        $batchMaxId = null;
        $sent = [];

        foreach ($this->pushCandidateRows($type, $full, $cursor) as $row) {
            $clientId = $this->clientId($type, $row);

            if (! isset($uploadSet[$clientId])) {
                continue;
            }

            // The bundle still has to be rebuilt for its gzip payload, but
            // the hash does not have to be recomputed from it: when this
            // row's updated_at has not moved since the manifest scan
            // captured it, the content is provably unchanged (the same
            // updated_at-vs-synced_at assumption DirtyRows and self-heal
            // already rely on), so the hash from that scan is still
            // correct. A row edited in the narrow window between the two
            // phases falls through to a fresh hash of the fresh bundle
            // here instead: reusing the stale hash would tag freshly
            // rebuilt bytes with the wrong digest, which the server would
            // simply bounce as a hash mismatch rather than accept quietly.
            $bundle = $this->build($type, $row);
            $captured = $hashes[$clientId] ?? null;
            $hash = ($captured !== null && $captured['updated_at'] === self::datetime($row->updated_at))
                ? $captured['hash']
                : CanonicalJson::hash($bundle);

            $rejection = SyncRejection::query()->where('type', $type)->where('client_id', $clientId)->first();

            if ($rejection !== null && hash_equals($rejection->hash, $hash)) {
                continue;
            }

            $gzip = gzencode(CanonicalJson::encode($bundle), 6);
            $bytes = strlen($gzip);

            if ($bytes > $limits['compressed_per_blob']) {
                SyncRejection::query()->updateOrCreate(
                    ['type' => $type, 'client_id' => $clientId],
                    ['reason' => 'too_large_local', 'hash' => $hash],
                );

                continue;
            }

            if ($batch !== [] && (count($batch) + 1 > $limits['blobs_per_request'] || $batchBytes + $bytes > $limits['compressed_per_request'])) {
                $this->flushPushBatch($type, $batch, $sent);

                if ($full) {
                    $this->advanceFullCursor($state, $batchMaxId);
                }

                $batch = [];
                $batchBytes = 0;
                $batchMaxId = null;
                $sent = [];
            }

            $batch[] = [
                'client_id' => $clientId,
                'hash' => $hash,
                'updated_at' => self::datetime($row->updated_at),
                'sidecar' => $this->sidecar($type, $row),
                'gzip' => $gzip,
            ];
            $batchBytes += $bytes;
            $sent[$clientId] = [
                'modelClass' => $row::class,
                'id' => $row->getKey(),
                'hash' => $hash,
                'updatedAt' => $row->updated_at,
            ];
            $batchMaxId = max($batchMaxId ?? 0, (int) $row->getKey());
        }

        $this->flushPushBatch($type, $batch, $sent);

        if ($full) {
            $this->advanceFullCursor($state, $batchMaxId);
        }
    }

    /**
     * Yields push candidate rows for $type in the order pushPhase should
     * upload them. Every case but one yields in plain id order via
     * lazyById; a match push in incremental mode instead yields
     * oldest-first by started_at, so a device's history lands in play order.
     *
     * That started_at order is deliberately not an orderBy() on the
     * paginated query itself (see {@see pushPhase}'s docblock for why that
     * silently drops or duplicates rows). Instead the ordered id list is
     * fetched once, in a single single-column query, and re-fetched (with
     * this type's eager loads, via candidateQuery) in chunks of 25 through
     * whereIn; a whereIn result carries no ordering guarantee of its own,
     * so each chunk is re-sorted back into started_at order in PHP against
     * the id list that was already in that order.
     *
     * @return iterable<int, Model>
     */
    private function pushCandidateRows(string $type, bool $full, ?int $cursor): iterable
    {
        if ($type !== 'match' || $full) {
            yield from $this->candidateQuery($type, $full, $cursor)->lazyById(25);

            return;
        }

        $ids = $this->candidateQuery($type, $full, $cursor)
            ->reorder()
            ->orderBy('started_at')
            ->pluck('id');

        foreach ($ids->chunk(25) as $chunk) {
            $rows = $this->candidateQuery($type, $full, $cursor)
                ->whereIn('id', $chunk)
                ->get()
                ->keyBy('id');

            foreach ($chunk as $id) {
                if ($rows->has($id)) {
                    yield $rows->get($id);
                }
            }
        }
    }

    /**
     * Marks the row identified by $modelClass/$id clean via a
     * query-builder update wrapped in withoutTimestamps(), rather than the
     * forceFill()->saveQuietly() pattern this used to follow: that pattern
     * still bumps updated_at to now() (saveQuietly only silences model
     * events, it does not skip timestamps), which would mask any edit
     * landing between the read that captured $syncedAt and this call (a
     * newer updated_at would be overwritten with "now", so the row's
     * updated_at <= synced_at comparison would read it as clean even
     * though it changed since). withoutTimestamps() leaves updated_at
     * exactly as it already is in the database, so a concurrent edit's
     * genuinely newer updated_at keeps the row dirty on the next scan.
     *
     * $syncedHash is omitted for a self-heal call (the row's synced_hash
     * already matches, nothing to change there) and supplied for a
     * confirmed push (the freshly stored hash).
     *
     * @param  class-string<Model>  $modelClass
     */
    private function markClean(string $modelClass, int|string $id, mixed $syncedAt, ?string $syncedHash = null): void
    {
        $values = ['synced_at' => $syncedAt];

        if ($syncedHash !== null) {
            $values['synced_hash'] = $syncedHash;
        }

        $modelClass::withoutTimestamps(
            fn () => $modelClass::query()->whereKey($id)->update($values)
        );
    }

    /**
     * Persists $state's full_cursor to $maxId, the highest row id in a
     * batch just flushed by pushPhase; a no-op when nothing was flushed
     * (an all-skip batch, or the very first call when $batch never
     * accumulated anything). Saved quietly: this is bookkeeping for the
     * resume machinery, not a change worth an updated_at bump or model
     * event of its own.
     */
    private function advanceFullCursor(SyncState $state, ?int $maxId): void
    {
        if ($maxId === null) {
            return;
        }

        $state->forceFill(['full_cursor' => $maxId])->saveQuietly();
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @param  array<string, array{modelClass: class-string<Model>, id: int|string, hash: string, updatedAt: mixed}>  $sent
     */
    private function flushPushBatch(string $type, array $batch, array $sent): void
    {
        if ($batch === []) {
            return;
        }

        $result = $this->api->uploadBlobs($type, $batch);

        SyncActivity::log(sprintf(
            '%s: uploaded %d%s.',
            $type,
            count($result['stored'] ?? []),
            ($rejectedCount = count($result['rejected'] ?? [])) > 0 ? ", {$rejectedCount} rejected" : '',
        ));

        if ($type === 'match') {
            $this->pushedMatches += count($result['stored'] ?? []);
        }

        foreach ($result['stored'] ?? [] as $storedId) {
            $entry = $sent[$storedId] ?? null;

            if ($entry === null) {
                continue;
            }

            // synced_at tracks the row's own updated_at as it stood when
            // this batch was built, not the wall-clock time the server's
            // confirmation arrived: see markClean().
            $this->markClean($entry['modelClass'], $entry['id'], $entry['updatedAt'], $entry['hash']);

            SyncRejection::query()->where('type', $type)->where('client_id', $storedId)->delete();
        }

        foreach ($result['rejected'] ?? [] as $rejected) {
            $clientId = $rejected['client_id'];
            $entry = $sent[$clientId] ?? null;

            if ($entry === null) {
                continue;
            }

            SyncRejection::query()->updateOrCreate(
                ['type' => $type, 'client_id' => $clientId],
                ['reason' => $rejected['reason'], 'hash' => $entry['hash']],
            );

            if ($rejected['reason'] === 'hash_mismatch') {
                Log::warning('Sync push: blob rejected for a hash mismatch.', [
                    'type' => $type,
                    'client_id' => $clientId,
                ]);
            }
        }
    }

    /**
     * Fetches the download ids in the server-given order, in slices of
     * blobs_per_request. A slice may come back short of what was asked
     * (the server's own byte budget), so each slice loops on whatever ids
     * are still missing until every id in it has arrived or a request
     * makes no progress at all, at which point that slice is abandoned
     * (logged) and the next slice is tried rather than spinning on a
     * request the server keeps starving; one starved slice must not cost
     * every later slice its turn.
     *
     * @param  list<string>  $downloadIds
     */
    private function pullPhase(string $type, array $downloadIds): void
    {
        if ($downloadIds === []) {
            return;
        }

        $total = count($downloadIds);
        $imported = 0;

        // A purely numeric client_id (an MTGO deck id) can arrive as a JSON
        // int from a server that passed it through a PHP array key; the
        // fetch endpoint validates client_ids.* as strings, so normalise
        // before anything is sent back.
        $downloadIds = array_map(static fn ($id): string => (string) $id, $downloadIds);

        $blobsPerRequest = max(1, (int) config('sync_client.limits.blobs_per_request'));

        foreach (array_chunk($downloadIds, $blobsPerRequest) as $slice) {
            $remaining = $slice;

            while ($remaining !== []) {
                $result = $this->api->fetchBlobs($type, $remaining);
                $blobs = $result['blobs'] ?? [];

                foreach ($blobs as $blob) {
                    $this->importBlob($type, $blob);
                }

                if ($blobs !== []) {
                    $imported += count($blobs);
                    SyncActivity::log(sprintf('%s: downloaded %d of %d.', $type, $imported, $total));

                    if ($type === 'match') {
                        $this->pulledMatches += count($blobs);
                    }
                }

                $received = array_map(static fn (array $blob): string => $blob['client_id'], $blobs);
                $next = array_values(array_diff($remaining, $received));

                // An empty response and a non-empty response that names
                // none of the ids we asked for are the same starvation
                // signal: without this check, the latter would leave
                // $remaining untouched and spin unchanged until the job's
                // own timeout killed it.
                if (count($next) === count($remaining)) {
                    Log::warning('Sync pull: a request made no progress on the requested ids.', [
                        'type' => $type,
                        'remaining' => count($remaining),
                    ]);

                    continue 2;
                }

                $remaining = $next;
            }
        }
    }

    /**
     * @param  array{client_id: string, hash: string, data: string}  $blob
     */
    /**
     * A quiet run (nothing moved) stays quiet; a run that moved matches
     * raises the same in-app toast the match recorder uses.
     */
    private function notifyCompletion(): void
    {
        if ($this->pushedMatches === 0 && $this->pulledMatches === 0) {
            return;
        }

        $parts = [];

        if ($this->pushedMatches > 0) {
            $parts[] = sprintf('%d %s uploaded', $this->pushedMatches, $this->pushedMatches === 1 ? 'match' : 'matches');
        }

        if ($this->pulledMatches > 0) {
            $parts[] = sprintf('%d %s downloaded', $this->pulledMatches, $this->pulledMatches === 1 ? 'match' : 'matches');
        }

        AppNotification::dispatch(
            type: 'sync',
            title: 'Synced with MyMTGO',
            message: implode(', ', $parts),
            route: '/settings',
        );
    }

    /**
     * Bundles are written straight to the tables inside
     * Model::withoutEvents(), and their decklists arrive as pre-built
     * signatures, so no part of an import ever reaches CreateMissingCards
     * the way log ingestion does. Left alone, a device that took its whole
     * history from the cloud ends up with an empty cards table: no covers,
     * no names, no images.
     *
     * Running this once per run rather than per blob keeps it to a single
     * scan, and it is skipped entirely when the run only pushed.
     * CreateMissingCards queues the Scryfall fill itself for anything new.
     */
    private function backfillCardsForImportedRows(): void
    {
        if (! $this->importedAnything) {
            return;
        }

        $created = CreateMissingCardsFromLocalData::run();

        if ($created > 0) {
            SyncActivity::log(sprintf('%d new %s queued for card details.', $created, $created === 1 ? 'card' : 'cards'));
        }
    }

    private function importBlob(string $type, array $blob): void
    {
        $raw = gzdecode(base64_decode($blob['data']));

        if ($raw === false || hash('sha256', $raw) !== $blob['hash']) {
            Log::warning('Sync pull: blob hash mismatch, skipping.', [
                'type' => $type,
                'client_id' => $blob['client_id'],
            ]);

            return;
        }

        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        // SQLite returns BUSY immediately (busy_timeout does not apply) when
        // a transaction that began with a read tries to take the write lock
        // while another writer holds it, and the import transaction does
        // exactly that under a busy pipeline. Retry the whole bundle with
        // backoff, and give up on just this blob rather than aborting the
        // run: an unimported blob simply stays in the manifest's download
        // list for the next run.
        try {
            retry(
                5,
                fn () => $this->import($type, $bundle, $blob['hash']),
                fn (int $attempt) => $attempt * 250,
                fn (Throwable $e) => str_contains($e->getMessage(), 'database is locked'),
            );

            $this->importedAnything = true;
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'database is locked')) {
                throw $e;
            }

            SyncActivity::log(sprintf('%s: %s left for the next run, the database stayed busy.', $type, $blob['client_id']));
            Log::warning('Sync pull: import kept hitting a locked database, skipping this blob.', [
                'type' => $type,
                'client_id' => $blob['client_id'],
            ]);
        }
    }

    /**
     * Eager-loads the type's builder relations onto the candidate query so
     * a lazyById scan (manifest and push both use this) costs roughly one
     * query per relation per chunk, not one per relation per row: the
     * builders' own loadMissing() is a per-row fallback for callers that
     * did not batch, and finds everything already loaded here.
     *
     * $cursor only applies in full mode: id > cursor picks the scan back up
     * past whatever an interrupted full run for this type already pushed.
     * Incremental mode never carries a cursor, DirtyRows::query() already
     * being small enough that a resume point buys nothing.
     */
    private function candidateQuery(string $type, bool $full, ?int $cursor = null): Builder
    {
        $query = $full ? $this->fullQuery($type) : DirtyRows::query($type);

        if ($full && $cursor !== null) {
            $query->where('id', '>', $cursor);
        }

        return $query->with($this->relations($type));
    }

    /**
     * Full mode widens the row set to every row, but not past the per-deck
     * gate: a match or league whose deck is not enabled for cloud sync (or
     * which has no deck at all) is never a candidate, in any mode, exactly
     * as in {@see DirtyRows::query}. Nor past the same free-tier limited
     * gate as {@see DirtyRows::base()}: a free account's match and league
     * arms drop limited decks too, since the slot row that would otherwise
     * gate them survives a tier lapse by design. Nor past the finished-match
     * gate: a live match is never sent. Must be kept in agreement with that
     * method.
     */
    private function fullQuery(string $type): Builder
    {
        return match ($type) {
            'match' => MtgoMatch::query()->finished()->whereHas('deckVersion.deck', fn (Builder $query) => $query
                ->where('cloud_sync_enabled', true)
                ->when(! AppSettings::isSupporter(), fn (Builder $q) => $q->withoutLimited())),
            'deck' => Deck::query()
                ->syncableIdentity()
                ->when(! AppSettings::isSupporter(), fn (Builder $query) => $query->withoutLimited()),
            'league' => League::query()->whereHas('deckVersion.deck', fn (Builder $query) => $query
                ->where('cloud_sync_enabled', true)
                ->when(! AppSettings::isSupporter(), fn (Builder $q) => $q->withoutLimited())),
        };
    }

    /**
     * @return array<int, string>
     */
    private function relations(string $type): array
    {
        return match ($type) {
            'match' => $this->matchBuilder->relations(),
            'deck' => $this->deckBuilder->relations(),
            'league' => $this->leagueBuilder->relations(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $type, Model $row): array
    {
        return match ($type) {
            'match' => $this->matchBuilder->build($row),
            'deck' => $this->deckBuilder->build($row),
            'league' => $this->leagueBuilder->build($row),
        };
    }

    private function clientId(string $type, Model $row): string
    {
        return match ($type) {
            'match' => $this->matchBuilder->clientId($row),
            'deck' => $this->deckBuilder->clientId($row),
            'league' => $this->leagueBuilder->clientId($row),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sidecar(string $type, Model $row): array
    {
        return match ($type) {
            'match' => $this->matchBuilder->sidecar($row),
            'deck' => $this->deckBuilder->sidecar($row),
            'league' => $this->leagueBuilder->sidecar($row),
        };
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function import(string $type, array $bundle, string $hash): void
    {
        match ($type) {
            'match' => $this->matchImporter->import($bundle, $hash),
            'deck' => $this->deckImporter->import($bundle, $hash),
            'league' => $this->leagueImporter->import($bundle, $hash),
        };
    }

    private static function datetime(?Carbon $value): ?string
    {
        return $value?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
