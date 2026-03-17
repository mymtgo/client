/**
 * Derive deck colour identity from card data.
 *
 * Only counts creatures/spells (not artifacts/lands).
 * Cards with no colour identity are treated as colourless (C).
 * Requires 4+ cards of a colour to include it.
 */
export function getDeckIdentity(
    maindeck: Record<string, App.Data.Front.CardData[]>,
    sideboard: App.Data.Front.CardData[],
): string | null {
    const ignoredTypes = ['Artifact', 'Land', 'Basic Land'];
    const allCards = [...Object.values(maindeck).flat(), ...sideboard];

    const colorCounts: Record<string, number> = {};

    for (const card of allCards) {
        if (!card.type || ignoredTypes.includes(card.type)) continue;

        const identity = card.identity?.trim();
        const colors = identity
            ? identity.split(',').map((c: string) => c.trim()).filter(Boolean)
            : ['C'];

        for (const color of colors) {
            colorCounts[color] = (colorCounts[color] ?? 0) + card.quantity;
        }
    }

    const colors = Object.entries(colorCounts)
        .filter(([, count]) => count >= 4)
        .map(([color]) => color);

    return colors.length > 0 ? colors.join(',') : null;
}
