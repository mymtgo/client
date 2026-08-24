/**
 * Shared card-type grouping for overlay card lists (draw odds, sideboard
 * guide): canonical Magic card types in display order, plus a normaliser that
 * collapses full type lines ("Legendary Creature — Eldrazi") onto them.
 */
export const CANONICAL_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'] as const;

export const TYPE_ORDER: Record<string, number> = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

export function normalizeType(raw: string | null | undefined): string {
    if (!raw) return 'Other';
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}

export function groupByType<T>(cards: T[], type: (card: T) => string | null | undefined): Record<string, T[]> {
    const merged: Record<string, T[]> = {};
    for (const card of cards) {
        const key = normalizeType(type(card));
        (merged[key] ??= []).push(card);
    }
    return Object.fromEntries(Object.entries(merged).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)));
}
