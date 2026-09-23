import type { ReplayFrame, ReplayLogEntry, ReplayLogItem, ReplayTurn } from './types';

const TURN_LINE = /^Turn \d+:?\s*$/;

/** Two turn-start candidates this close together are the same turn seen by two sources. */
const SAME_TURN_WINDOW_MS = 2000;

/** Milliseconds since midnight for an `H:i:s` or `H:i:s.v` timestamp. */
export function timestampMs(timestamp: string): number {
    const [clock, fraction] = timestamp.split('.');
    const [h, m, s] = clock.split(':').map(Number);

    return ((h || 0) * 3600 + (m || 0) * 60 + (s || 0)) * 1000 + Number((fraction ?? '0').padEnd(3, '0').slice(0, 3));
}

/**
 * Frame index a log line belongs to: the first frame in or after the line's second.
 * Log lines only carry whole seconds, frames may carry milliseconds.
 */
export function frameForTimestamp(frames: ReplayFrame[], timestamp: string): number {
    const second = timestamp.slice(0, 8);
    const index = frames.findIndex((frame) => frame.timestamp.slice(0, 8) >= second);

    return index === -1 ? frames.length - 1 : index;
}

/**
 * Turn boundaries for the scrubber and the log.
 *
 * MTGO numbers turns per round and the sidecar only reports a turn when that
 * round number changes, so neither source alone sees every turn. The log's
 * "Turn N:" lines and the frames' Turn changes are merged, and the active
 * player alternates from the seat that played first.
 */
export function deriveTurns(frames: ReplayFrame[], log: ReplayLogEntry[]): ReplayTurn[] {
    if (!frames.length) {
        return [];
    }

    const candidates = new Set<number>();

    log.filter((entry) => TURN_LINE.test(entry.message)).forEach((entry) => candidates.add(frameForTimestamp(frames, entry.timestamp)));

    frames.forEach((frame, index) => {
        const previous = frames[index - 1]?.content.Turn;

        if (index > 0 && previous != null && frame.content.Turn != null && frame.content.Turn !== previous) {
            candidates.add(index);
        }
    });

    const starts: number[] = [];

    [...candidates]
        .sort((a, b) => a - b)
        .forEach((index) => {
            const last = starts[starts.length - 1];

            if (last === undefined || timestampMs(frames[index].timestamp) - timestampMs(frames[last].timestamp) > SAME_TURN_WINDOW_MS) {
                starts.push(index);
            }
        });

    const seats = frames[0].content.Players.map((player) => player.Id);
    const firstSeat = startingSeat(frames, log, starts[0]);

    const turns: ReplayTurn[] = starts.map((from, i) => ({
        number: i + 1,
        from,
        to: starts[i + 1] ?? frames.length,
        player: firstSeat === null || seats.length !== 2 ? null : seats[(seats.indexOf(firstSeat) + i) % 2],
    }));

    if (!turns.length || turns[0].from > 0) {
        turns.unshift({ number: null, from: 0, to: turns[0]?.from ?? frames.length, player: null });
    }

    return turns;
}

function startingSeat(frames: ReplayFrame[], log: ReplayLogEntry[], firstTurnFrame: number | undefined): number | null {
    const players = frames[0].content.Players;

    for (const entry of log) {
        const match = entry.message.match(/^(.+?) chooses to (play|draw) first/);

        if (!match) {
            continue;
        }

        const chooser = players.find((player) => player.Name === match[1]);
        const other = players.find((player) => player.Name !== match[1]);

        if (chooser && other) {
            return match[2] === 'play' ? chooser.Id : other.Id;
        }
    }

    const seeded = firstTurnFrame === undefined ? undefined : frames[firstTurnFrame]?.content.ActivePlayer;

    return seeded ?? null;
}

/** Log rows for the drawer: a header per turn, then each line pinned to its frame. */
export function buildLogItems(frames: ReplayFrame[], log: ReplayLogEntry[], turns: ReplayTurn[]): ReplayLogItem[] {
    const headers = turns
        .filter((turn) => turn.number !== null)
        .map((turn) => ({
            kind: 'header' as const,
            timestamp: frames[turn.from].timestamp.slice(0, 8),
            text: `Turn ${turn.number}`,
            frame: turn.from,
            player: turn.player,
        }));

    const lines = log
        .filter((entry) => !TURN_LINE.test(entry.message))
        .map((entry) => ({
            kind: 'line' as const,
            timestamp: entry.timestamp,
            text: entry.message,
            frame: frameForTimestamp(frames, entry.timestamp),
            player: null,
        }));

    return [...headers, ...lines]
        .map((item, order) => ({ item, order }))
        .sort((a, b) => a.item.frame - b.item.frame || (a.item.kind === b.item.kind ? a.order - b.order : a.item.kind === 'header' ? -1 : 1))
        .map(({ item }, key) => ({ ...item, key }));
}

/** The turn a frame falls in. */
export function turnAt(turns: ReplayTurn[], frame: number): ReplayTurn | null {
    return turns.find((turn) => frame >= turn.from && frame < turn.to) ?? null;
}
