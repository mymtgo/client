/** MTGO turns a player's timer red under five minutes; the app matches it. */
export const CLOSE_TO_TIME_MS = 5 * 60 * 1000;

/** Format a remaining match clock as MTGO shows it, e.g. 481000 -> "8:01". */
export function formatClock(ms: number): string {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

export function isCloseToTime(ms: number): boolean {
    return ms < CLOSE_TO_TIME_MS;
}
