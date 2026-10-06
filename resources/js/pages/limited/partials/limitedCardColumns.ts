import type { LimitedCardRow, PoolStatus } from '@/types/limited';

export type SortKey = 'pick' | 'name' | 'status' | 'gamesCast' | 'winPctCast' | 'seenCount' | 'wheeled' | 'prior';

/** `draftOnly` columns describe picks, so sealed (no picks) hides them. */
export type CardColumn = { key: SortKey; label: string; align: 'left' | 'right' | 'center'; draftOnly?: boolean };

export const COLUMNS: CardColumn[] = [
    { key: 'name', label: 'Card', align: 'left' },
    { key: 'pick', label: 'Pick', align: 'left', draftOnly: true },
    { key: 'status', label: 'In deck', align: 'center' },
    { key: 'gamesCast', label: 'Games cast', align: 'right' },
    { key: 'winPctCast', label: 'Win % cast', align: 'right' },
    { key: 'seenCount', label: 'Seen', align: 'right', draftOnly: true },
    { key: 'wheeled', label: 'Wheeled', align: 'center', draftOnly: true },
    { key: 'prior', label: 'Prior drafts', align: 'right', draftOnly: true },
];

/** Ascending by default for these: the rest read best largest first. */
export const ASCENDING_BY_DEFAULT: SortKey[] = ['name', 'pick', 'status'];

const STATUS_RANK: Record<PoolStatus, number> = { main: 0, side: 1, pool: 2, cut: 3 };

export function compareRows(a: LimitedCardRow, b: LimitedCardRow, key: SortKey, names: (id: number) => string): number {
    switch (key) {
        case 'name':
            return names(a.catalogId).localeCompare(names(b.catalogId));
        case 'pick':
            return (a.ordinals[0] ?? Number.MAX_SAFE_INTEGER) - (b.ordinals[0] ?? Number.MAX_SAFE_INTEGER);
        case 'status':
            return STATUS_RANK[a.status] - STATUS_RANK[b.status];
        case 'gamesCast':
            return a.gamesCast - b.gamesCast;
        case 'winPctCast':
            return (a.winPctCast ?? -1) - (b.winPctCast ?? -1);
        case 'seenCount':
            return a.seenCount - b.seenCount;
        case 'wheeled':
            return Number(a.wheeled) - Number(b.wheeled);
        case 'prior':
            return a.priorTaken - b.priorTaken;
    }
}
