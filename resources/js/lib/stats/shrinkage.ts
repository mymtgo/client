/**
 * Keys for the win-rate columns we shrink. Drives sort, display, and color.
 */
export const SHRINK_KEYS = ['kept', 'cast', 'seen', 'pregame'] as const;
export type ShrinkKey = (typeof SHRINK_KEYS)[number];

export interface ShrinkageInput {
    readonly wins: number;
    readonly games: number;
    readonly prior: number; // 0..1
    readonly strength: number; // pseudo-games (k), >= 0
}

/**
 * Beta-Binomial shrinkage estimator.
 *   shrunk = (wins + k * prior) / (games + k)
 *
 * Defensive: non-finite or out-of-range inputs collapse to the prior or raw
 * rate as appropriate. Output is clamped to [0, 1].
 */
export function shrunkRate(input: ShrinkageInput): number {
    const prior = clamp01(toFiniteOr(input.prior, 0.5));
    const wins = nonNegativeFinite(input.wins);
    const games = nonNegativeFinite(input.games);
    const strength = nonNegativeFinite(input.strength);
    const denom = games + strength;

    if (denom === 0) {
        return prior;
    }

    const value = (wins + strength * prior) / denom;
    return clamp01(value);
}

/**
 * Convenience: raw rate, or null when undefined.
 */
export function rawRate(wins: number, games: number): number | null {
    if (!Number.isFinite(wins) || !Number.isFinite(games) || games <= 0) {
        return null;
    }
    return clamp01(wins / games);
}

/**
 * Invert a rate for "theirs" perspective. Returns `1 - rate`.
 * Used so opponent stats read as "their win rate."
 */
export function perspectiveAdjust(rate: number, perspective: 'mine' | 'theirs'): number {
    return perspective === 'theirs' ? 1 - rate : rate;
}

function clamp01(value: number): number {
    if (!Number.isFinite(value)) return 0.5;
    if (value < 0) return 0;
    if (value > 1) return 1;
    return value;
}

function nonNegativeFinite(value: number): number {
    if (!Number.isFinite(value) || value < 0) return 0;
    return value;
}

function toFiniteOr(value: number, fallback: number): number {
    return Number.isFinite(value) ? value : fallback;
}
