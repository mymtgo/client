/**
 * Canonical MTG permanent/spell types in display order. The first match
 * wins, so Creature beats Artifact for "Artifact Creature".
 */
export const CANONICAL_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'] as const;

export const TYPE_ORDER: Record<string, number> = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

export function normalizeType(raw: string): string {
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}
