<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\SyncRejection;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\LeagueClientId;
use Illuminate\Database\Eloquent\Builder;

/**
 * The server's slot ledger is the authority on which decks sync (spec
 * 2026-09-10, rule 5). Every manifest carries it; this writes it down:
 * the summary into settings for the UI, and one boolean per deck for the
 * queries. Cooling rows count as off.
 *
 * Matches and leagues whose deck is enabled get their parked
 * deck_not_synced rejections cleared, so the next push offers those rows
 * again; rows whose deck is still off keep theirs.
 */
class ApplyDeckSyncSlots
{
    /**
     * Trashed decks are included (a deleted deck must stay off, and the
     * server still lists it while its slot cools) and the writes go through
     * toBase(), so updated_at is left alone: bumping it would mark every
     * deck dirty and push the whole shelf back up on the next run.
     *
     * @param  array{limit?: int|null, used?: int, decks?: list<array{client_id: string, enabled_at: string, disabled_at: string|null, frees_at: string|null}>}  $slots
     */
    public static function run(array $slots): void
    {
        AppSettings::setSyncSlots($slots);

        $enabledClientIds = array_flip(collect($slots['decks'] ?? [])
            ->filter(fn (array $deck) => ($deck['disabled_at'] ?? null) === null)
            ->map(fn (array $deck) => (string) $deck['client_id'])
            ->all());

        $toEnable = [];
        $toDisable = [];

        $decks = Deck::withTrashed()->select(['id', 'mtgo_id', 'cloud_sync_enabled', 'deleted_at'])->toBase()->cursor();

        foreach ($decks as $deck) {
            // A trashed deck is only ever lowered, never raised. Its delete
            // may have failed to reach the server (DestroyController
            // swallows that by design), in which case the ledger still
            // lists it enabled, and honouring that would keep syncing a
            // deck the user deleted. SyncRunner retries the disable; until
            // it lands, the local flag stays down.
            $enabled = $deck->deleted_at === null
                && isset($enabledClientIds[DeckClientId::for((string) $deck->mtgo_id)]);

            if ($enabled === (bool) $deck->cloud_sync_enabled) {
                continue;
            }

            if ($enabled) {
                $toEnable[] = $deck->id;
            } else {
                $toDisable[] = $deck->id;
            }
        }

        self::setFlag($toEnable, true);
        self::setFlag($toDisable, false);
        self::forgetSyncedState($toEnable);

        self::clearParkedRejections();
    }

    /**
     * Nulls synced_hash and synced_at on every match and league of a deck
     * that just became enabled, so the next manifest offers them as dirty
     * and the server decides by hash what it actually needs.
     *
     * A row's local "clean" mark only says the server held it at some
     * point. While its deck was off the server may have shed it (the slot
     * migration truncated every stored resource, and a future retention
     * policy could do the same), and an incremental run trusts a matching
     * synced_hash without asking. Forgetting on enable is the one moment
     * that is cheap enough to re-verify the whole deck: an equal hash
     * costs the server a compare and nothing is uploaded twice.
     * updated_at is left alone (toBase()), so nothing here reads as an
     * edit.
     *
     * @param  list<int>  $deckIds
     */
    private static function forgetSyncedState(array $deckIds): void
    {
        foreach (array_chunk($deckIds, 500) as $chunk) {
            $versionIds = DeckVersion::query()->whereIn('deck_id', $chunk)->toBase()->pluck('id')->all();

            foreach (array_chunk($versionIds, 500) as $versions) {
                MtgoMatch::query()->whereIn('deck_version_id', $versions)->toBase()
                    ->update(['synced_hash' => null, 'synced_at' => null]);
                League::query()->whereIn('deck_version_id', $versions)->toBase()
                    ->update(['synced_hash' => null, 'synced_at' => null]);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private static function setFlag(array $ids, bool $enabled): void
    {
        // Chunked to stay under the bound-parameter ceiling on a collection
        // large enough to matter.
        foreach (array_chunk($ids, 500) as $chunk) {
            Deck::withTrashed()->whereIn('id', $chunk)->toBase()->update(['cloud_sync_enabled' => $enabled]);
        }
    }

    /**
     * Drops every deck_not_synced rejection whose row now belongs to an
     * enabled deck, so the next push offers those rows again.
     *
     * Keyed off the current flag rather than off this apply's transitions:
     * a deck disabled and re-enabled elsewhere between two runs arrives here
     * already enabled locally, and a transition-only cleanup would leave its
     * rejection parked until the row's content happened to change. Rows
     * whose deck is still off keep their rejection, which is what stops the
     * runner re-offering them every half hour.
     */
    private static function clearParkedRejections(): void
    {
        // Nothing parked is the overwhelmingly common case, and this runs
        // once per type per run, so it buys its way out on one cheap check.
        if (! SyncRejection::query()->where('reason', 'deck_not_synced')->exists()) {
            return;
        }

        $enabled = fn (Builder $query) => $query->where('cloud_sync_enabled', true);

        $matchIds = MtgoMatch::query()
            ->whereHas('deckVersion.deck', $enabled)
            ->pluck('token')
            ->all();

        // League client ids are derived from (token, started_at), the same
        // pairing DirtyRows::knownIds() maps, so they cannot be selected in
        // SQL and are built here instead.
        $leagueIds = League::query()
            ->whereHas('deckVersion.deck', $enabled)
            ->get(['token', 'started_at'])
            ->map(fn (League $league) => LeagueClientId::for($league))
            ->all();

        self::deleteRejections('match', $matchIds);
        self::deleteRejections('league', $leagueIds);
    }

    /**
     * @param  list<string>  $clientIds
     */
    private static function deleteRejections(string $type, array $clientIds): void
    {
        foreach (array_chunk($clientIds, 500) as $chunk) {
            SyncRejection::query()
                ->where('type', $type)
                ->where('reason', 'deck_not_synced')
                ->whereIn('client_id', $chunk)
                ->delete();
        }
    }
}
