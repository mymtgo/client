<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Builder;

class DirtyRows
{
    /**
     * Rows whose content may have moved since the last confirmed sync. The
     * grouping is required: an ungrouped orWhere escapes any surrounding
     * constraint and would return the whole table. Rebuilding bundles is the
     * expensive part, not scanning; a clean row never rebuilds.
     */
    public static function query(string $type): Builder
    {
        return self::base($type)->where(function (Builder $query): void {
            $query->whereNull('synced_hash')
                ->orWhereColumn('updated_at', '>', 'synced_at');
        });
    }

    /**
     * Every client_id this device holds, from a single-column select with no
     * bundle rebuild. Sent complete on the manifest request carrying last.
     *
     * @return list<string>
     */
    public static function knownIds(string $type): array
    {
        return match ($type) {
            'match' => self::base('match')->pluck('token')->all(),
            'deck' => self::base('deck')->pluck('mtgo_id')->map(fn ($id) => DeckClientId::for((string) $id))->all(),
            'league' => self::base('league')->get(['token', 'started_at'])
                ->map(fn (League $league) => LeagueClientId::for($league))
                ->all(),
        };
    }

    /**
     * Deck and League use SoftDeletes, so their default query scope already
     * excludes trashed rows; matches have no soft deletes. Matches and
     * leagues are further restricted to decks enabled for cloud sync: a row
     * whose deck is off (or which has no deck) is neither dirty nor known,
     * so the server is never told about it (spec 2026-09-10, rule 2).
     *
     * DeckVersion::deck() is declared withTrashed(), so this whereHas does
     * not exclude a soft-deleted deck on its own. That is fine: a deleted
     * deck is excluded by its flag, which the delete path clears and
     * ApplyDeckSyncSlots keeps false afterwards.
     *
     * Limited decks are a supporter feature, so a free account's deck arm
     * additionally drops every `limited:%` deck. The local-only
     * `limited:league-%` shape has no cross-device identity and never
     * syncs, on any tier.
     *
     * A free account's match and league arms drop limited decks too, not
     * only enabled=false ones: the slot row that gates them survives a
     * tier lapse by design (only the free-tier migration ever deletes one),
     * so a lapsed supporter's limited deck can stay enabled locally while
     * their matches, leagues, drafts and picks must stop syncing the moment
     * the tier flips. Must be kept in agreement with
     * {@see SyncRunner::fullQuery()}.
     */
    private static function base(string $type): Builder
    {
        return match ($type) {
            'match' => MtgoMatch::query()->whereHas('deckVersion.deck', fn (Builder $query) => $query
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
}
