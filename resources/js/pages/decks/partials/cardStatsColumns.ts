export const CARD_STATS_COLUMNS = [
    { key: 'type', label: 'Type' },
    { key: 'sb', label: 'SB' },
    { key: 'keptPct', label: 'Kept %' },
    { key: 'keptWinPct', label: 'Kept Win %' },
    { key: 'castPct', label: 'Cast %' },
    { key: 'castWinPct', label: 'Cast Win %' },
    { key: 'playedPct', label: 'Played %' },
    { key: 'kicked', label: 'Kicked' },
    { key: 'activated', label: 'Activated' },
    { key: 'pregamePct', label: 'Pregame %' },
    { key: 'pregameWinPct', label: 'Pregame Win %' },
    { key: 'seenPct', label: 'Seen %' },
    { key: 'seenWinPct', label: 'Seen Win %' },
    { key: 'sbOutPct', label: 'SB Out %' },
    { key: 'sbInPct', label: 'SB In %' },
    { key: 'games', label: 'Games' },
] as const;

export type CardStatsColumnKey = (typeof CARD_STATS_COLUMNS)[number]['key'];

export type CardStatsVisibility = Record<CardStatsColumnKey, boolean>;

export type CardStatsPerspective = 'mine' | 'theirs';

/**
 * Columns that only make sense when viewing the local player's deck.
 * Opponent rows have no deck quantity, hand visibility, or sideboard tracking.
 */
export const LOCAL_ONLY_COLUMNS: readonly CardStatsColumnKey[] = ['sb', 'keptPct', 'keptWinPct', 'sbOutPct', 'sbInPct'] as const;

export const DEFAULT_CARD_STATS_VISIBILITY: CardStatsVisibility = Object.fromEntries(
    CARD_STATS_COLUMNS.map((col) => [col.key, true]),
) as CardStatsVisibility;

export const CARD_STATS_VISIBILITY_STORAGE_KEY = 'cardStatsVisibleColumns';

export function loadCardStatsVisibility(): CardStatsVisibility {
    try {
        const raw = localStorage.getItem(CARD_STATS_VISIBILITY_STORAGE_KEY);
        if (!raw) return { ...DEFAULT_CARD_STATS_VISIBILITY };

        const parsed: unknown = JSON.parse(raw);
        if (typeof parsed !== 'object' || parsed === null) {
            return { ...DEFAULT_CARD_STATS_VISIBILITY };
        }

        const record = parsed as Record<string, unknown>;
        const merged: CardStatsVisibility = { ...DEFAULT_CARD_STATS_VISIBILITY };
        for (const col of CARD_STATS_COLUMNS) {
            const value = record[col.key];
            if (typeof value === 'boolean') merged[col.key] = value;
        }
        return merged;
    } catch {
        return { ...DEFAULT_CARD_STATS_VISIBILITY };
    }
}

export function saveCardStatsVisibility(visibility: CardStatsVisibility): void {
    localStorage.setItem(CARD_STATS_VISIBILITY_STORAGE_KEY, JSON.stringify(visibility));
}
