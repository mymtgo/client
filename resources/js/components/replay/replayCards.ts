import type { ReplayCard } from './types';

const FRONT_ROW_TYPES = ['Creature', 'Planeswalker', 'Battle'];

/**
 * Whether a permanent belongs on the front row. An impending enchantment
 * creature with time counters is not a creature yet, so it sits back with
 * the lands and other non-creature permanents.
 */
export function isFrontRow(card: ReplayCard): boolean {
    const type = card.type ?? '';

    if (!type) {
        return card.Power != null;
    }

    if (type.includes('Land')) {
        return false;
    }

    if (type.includes('Enchantment') && timeCounters(card) > 0) {
        return false;
    }

    return FRONT_ROW_TYPES.some((kind) => type.includes(kind));
}

export function isLand(card: ReplayCard): boolean {
    return (card.type ?? '').includes('Land');
}

export function isCreature(card: ReplayCard): boolean {
    const type = card.type ?? '';

    return (type ? type.includes('Creature') : card.Power != null) && isFrontRow(card);
}

function timeCounters(card: ReplayCard): number {
    return Object.entries(card.Counters ?? {}).find(([kind]) => kind.toLowerCase() === 'time')?.[1] ?? 0;
}

export type CardGroup = { card: ReplayCard; count: number };

/**
 * Back row: lands first, then other non-creature permanents. Identical lands
 * with the same tapped state collapse into one card with a count.
 */
export function backRowGroups(cards: ReplayCard[]): CardGroup[] {
    const groups: CardGroup[] = [];
    const lands = new Map<string, CardGroup>();

    [...cards]
        .sort((a, b) => Number(isLand(b)) - Number(isLand(a)))
        .forEach((card) => {
            if (!isLand(card)) {
                groups.push({ card, count: 1 });

                return;
            }

            const key = `${card.CatalogID}|${card.Tapped ? 1 : 0}`;
            const existing = lands.get(key);

            if (existing) {
                existing.count++;

                return;
            }

            const group = { card, count: 1 };
            lands.set(key, group);
            groups.push(group);
        });

    return groups;
}

export function counterLabel(kind: string, count: number): string {
    return kind === '+1/+1' ? `+${count}/+${count}` : `${count} ${kind.toLowerCase()}`;
}

export function counterList(card: ReplayCard): { kind: string; label: string }[] {
    return Object.entries(card.Counters ?? {})
        .filter(([, count]) => count > 0)
        .map(([kind, count]) => ({ kind, label: counterLabel(kind, count) }));
}

/** Expand a mana pool into one symbol per mana, in WUBRG then colourless order. */
export function poolSymbols(pool: Record<string, number> | [] | undefined): string[] {
    if (!pool || Array.isArray(pool)) {
        return [];
    }

    const order = ['W', 'U', 'B', 'R', 'G', 'C'];

    return Object.entries(pool)
        .sort(([a], [b]) => ((order.indexOf(a) + 7) % 7) - ((order.indexOf(b) + 7) % 7))
        .flatMap(([symbol, count]) => Array.from({ length: count }, () => symbol));
}
