import type { ShrinkKey } from '@/lib/stats/shrinkage';
import type { CardStatsPerspective } from '@/pages/decks/partials/cardStatsColumns';

/**
 * Shared display formatters for card-stat cells/tiles. Kept framework-agnostic so
 * both the table row (`DeckCardStatsRow`) and the grid tile (`DeckCardStatsGridCard`)
 * render identical values.
 */

/**
 * Round a ratio to a whole-number percentage, or `null` when there's no denominator.
 */
export function pct(num: number, denom: number): number | null {
    return denom > 0 ? Math.round((num / denom) * 100) : null;
}

/**
 * The shrinkage-adjusted win rate for a metric, as a whole-number percentage.
 */
export function shrunkWinPct(shrunk: Readonly<Record<ShrinkKey, number>>, key: ShrinkKey): number {
    return Math.round(shrunk[key] * 100);
}

/**
 * The deck's baseline win rate from the given perspective, as a display percentage.
 */
export function baselinePct(prior: number, perspective: CardStatsPerspective): number {
    const adjusted = perspective === 'theirs' ? 1 - prior : prior;
    return Math.round(adjusted * 100);
}

/**
 * Tooltip label explaining the raw numbers (and shrinkage adjustment) behind a win %.
 */
export function rawWinPctLabel(
    rawRates: Readonly<Record<ShrinkKey, number | null>>,
    samples: Readonly<Record<ShrinkKey, number>>,
    key: ShrinkKey,
    trust: number,
    prior: number,
    perspective: CardStatsPerspective,
): string {
    const raw = rawRates[key];
    const games = samples[key];
    if (raw === null || games === 0) return 'no data';
    const rawLabel = `Raw ${Math.round(raw * 100)}% over ${games} game${games === 1 ? '' : 's'}`;
    if (trust <= 0) return rawLabel;
    return `${rawLabel} · adjusted toward ${baselinePct(prior, perspective)}% deck baseline`;
}

/**
 * Win-rate colour class. The meaning inverts for opponent ("theirs") cards: a high
 * win rate against you is bad for them, so it shows as destructive.
 */
export function winRateClass(pctVal: number, perspective: CardStatsPerspective): string {
    if (perspective === 'theirs') {
        if (pctVal > 55) return 'text-destructive';
        if (pctVal < 45) return 'text-success';
        return '';
    }
    if (pctVal > 55) return 'text-success';
    if (pctVal < 45) return 'text-destructive';
    return '';
}
