export type MatchRecordStyle = 'letters' | 'dashes';

/**
 * Format a W-L-D record. Draws are shown only when there are any.
 * This is the only place the frontend builds a record string.
 */
export function formatMatchRecord(wins: number, losses: number, draws: number, style: MatchRecordStyle = 'letters'): string {
    if (style === 'dashes') {
        return draws > 0 ? `${wins} - ${losses} - ${draws}` : `${wins} - ${losses}`;
    }

    return draws > 0 ? `${wins}W - ${losses}L - ${draws}D` : `${wins}W - ${losses}L`;
}
