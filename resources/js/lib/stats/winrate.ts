export type WinrateTone = 'win' | 'muted' | 'loss';

/** v2 rule — the one winrate colouring: ≥55 win · 45–55 muted · ≤45 loss. Bar fill and label always agree. */
export function winrateTone(pct: number): WinrateTone {
    if (pct >= 55) {
        return 'win';
    }

    if (pct <= 45) {
        return 'loss';
    }

    return 'muted';
}
