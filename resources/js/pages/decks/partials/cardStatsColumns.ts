import type { DeckCardStat } from '@/types/decks';

/**
 * Casting-method count columns (hidden by default). `statField` is the
 * DeckCardStat total the column reads and sorts on.
 */
export const CASTING_METHOD_COLUMNS = [
    { key: 'warp', label: 'Warp', statField: 'totalWarp' },
    { key: 'freeCast', label: 'Free Cast', statField: 'totalFreeCast' },
    { key: 'bargained', label: 'Bargain', statField: 'totalBargained' },
    { key: 'dashed', label: 'Dash', statField: 'totalDashed' },
    { key: 'bestowed', label: 'Bestow', statField: 'totalBestowed' },
    { key: 'replicated', label: 'Replicate', statField: 'totalReplicated' },
    { key: 'spectacle', label: 'Spectacle', statField: 'totalSpectacle' },
    { key: 'rebound', label: 'Rebound', statField: 'totalRebound' },
    { key: 'escaped', label: 'Escape', statField: 'totalEscaped' },
    { key: 'ninjutsu', label: 'Ninjutsu', statField: 'totalNinjutsu' },
    { key: 'suspended', label: 'Suspend', statField: 'totalSuspended' },
    { key: 'buyback', label: 'Buyback', statField: 'totalBuyback' },
    { key: 'disturb', label: 'Disturb', statField: 'totalDisturb' },
    { key: 'foretold', label: 'Foretell', statField: 'totalForetold' },
    { key: 'retraced', label: 'Retrace', statField: 'totalRetraced' },
    { key: 'mayhem', label: 'Mayhem', statField: 'totalMayhem' },
    { key: 'miracle', label: 'Miracle', statField: 'totalMiracle' },
    { key: 'gifted', label: 'Gift', statField: 'totalGifted' },
    { key: 'casualty', label: 'Casualty', statField: 'totalCasualty' },
] as const satisfies readonly { key: string; label: string; statField: keyof DeckCardStat }[];

export type CastingMethodColumnKey = (typeof CASTING_METHOD_COLUMNS)[number]['key'];

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
    ...CASTING_METHOD_COLUMNS.map(({ key, label }) => ({ key, label })),
    { key: 'pregamePct', label: 'Pregame %' },
    { key: 'pregameWinPct', label: 'Pregame Win %' },
    { key: 'seenPct', label: 'Seen %' },
    { key: 'seenWinPct', label: 'Seen Win %' },
    { key: 'sbOutPct', label: 'SB Out %' },
    { key: 'sbInPct', label: 'SB In %' },
    { key: 'games', label: 'Games' },
] as const;

export type CardStatsColumnKey = (typeof CARD_STATS_COLUMNS)[number]['key'];

/**
 * Picker groupings — display-only, the table keeps CARD_STATS_COLUMNS order.
 */
export const CARD_STATS_COLUMN_GROUPS: readonly { label: string; keys: readonly CardStatsColumnKey[] }[] = [
    { label: 'Card info', keys: ['type', 'sb'] },
    {
        label: 'Game metrics',
        keys: [
            'keptPct', 'keptWinPct', 'castPct', 'castWinPct', 'playedPct',
            'pregamePct', 'pregameWinPct', 'seenPct', 'seenWinPct',
            'sbOutPct', 'sbInPct', 'games',
        ],
    },
    {
        label: 'Casting costs',
        keys: ['kicked', 'activated', ...CASTING_METHOD_COLUMNS.map((col) => col.key)],
    },
];

export type CardStatsVisibility = Record<CardStatsColumnKey, boolean>;

export type CardStatsPerspective = 'mine' | 'theirs';

/**
 * Columns that only make sense when viewing the local player's deck.
 * Opponent rows have no deck quantity, hand visibility, or sideboard tracking.
 */
export const LOCAL_ONLY_COLUMNS: readonly CardStatsColumnKey[] = ['sb', 'keptPct', 'keptWinPct', 'sbOutPct', 'sbInPct'] as const;

const CASTING_METHOD_KEYS: readonly string[] = CASTING_METHOD_COLUMNS.map((col) => col.key);

export const DEFAULT_CARD_STATS_VISIBILITY: CardStatsVisibility = Object.fromEntries(
    CARD_STATS_COLUMNS.map((col) => [col.key, !CASTING_METHOD_KEYS.includes(col.key)]),
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
