/**
 * The five mana colours as they are drawn in ManaSymbols.vue, plus colourless.
 * Kept here so anything tinting a surface by colour identity reads from the
 * same palette the symbols themselves use.
 */
export const MANA_COLORS: Record<string, string> = {
    W: '#F8F6D8',
    U: '#C1D7E9',
    B: '#BAB1AB',
    R: '#E49977',
    G: '#A3C095',
    C: '#CCC2C0',
};

const NEUTRAL = '#8A8A8A';

/** Split a stored identity ("W,U") into its known colour hexes. */
export function manaColorsFor(identity: string | null | undefined): string[] {
    return (identity ?? '')
        .split(',')
        .map((symbol) => MANA_COLORS[symbol.trim().toUpperCase()])
        .filter(Boolean);
}

function withAlpha(hex: string, alpha: number): string {
    const value = hex.replace('#', '');
    const r = parseInt(value.slice(0, 2), 16);
    const g = parseInt(value.slice(2, 4), 16);
    const b = parseInt(value.slice(4, 6), 16);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * A diagonal wash in a deck's colours, faint enough to sit under text.
 *
 * Used where a deck has no cover art: the tile still needs to fill the space
 * art would have taken, and the identity is the only thing about the deck a
 * glance can usefully carry there.
 */
export function manaWash(identity: string | null | undefined, alpha = 0.28): string {
    const colors = manaColorsFor(identity);
    const palette = colors.length > 0 ? colors : [NEUTRAL, NEUTRAL];
    const stops = palette.length === 1 ? [palette[0], palette[0]] : palette;
    const step = 100 / (stops.length - 1);

    const gradient = stops.map((color, index) => `${withAlpha(color, alpha)} ${Math.round(index * step)}%`).join(', ');

    return `linear-gradient(135deg, ${gradient})`;
}
