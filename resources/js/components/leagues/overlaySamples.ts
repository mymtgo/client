import type { OverlayState, OverlayStatus } from '@/components/leagues/LeagueOverlayCard.vue';

const sampleDeck = { id: 0, name: 'tron v3 final', archetype: 'Mono Green Tron', colorIdentity: ['G'], label: null };
const event = { kind: 'league' as const, name: 'Modern League', format: 'Modern' };

/** A preview state; `base.deck` swaps in the real last-played deck so its label can be edited. */
export function sampleOverlayState(
    status: OverlayStatus,
    base: { art: string | null; size: 'full' | 'compact'; deck?: OverlayState['deck'] },
): OverlayState {
    const common = { art: base.art ? { url: base.art } : null, size: base.size, deck: base.deck ?? sampleDeck, event };
    const empty = { record: null, progress: null, match: null, gameRecord: null };

    switch (status) {
        case 'in_game':
            return {
                ...common,
                ...empty,
                status,
                record: { wins: 3, losses: 1 },
                progress: { played: 4, of: 5 },
                match: { number: 5, games: [{ won: true }, { won: false }, { won: null }], gamesWon: 1, gamesLost: 1 },
            };
        case 'sideboarding':
            return {
                ...common,
                ...empty,
                status,
                record: { wins: 3, losses: 1 },
                progress: { played: 4, of: 5 },
                match: { number: 5, games: [{ won: true }], gamesWon: 1, gamesLost: 0 },
            };
        case 'waiting':
            return { ...common, ...empty, status, record: { wins: 3, losses: 1 }, progress: { played: 4, of: 5 } };
        case 'complete':
            return { ...common, ...empty, status, record: { wins: 4, losses: 1 }, progress: { played: 5, of: 5 }, gameRecord: { won: 9, lost: 4 } };
        case 'trophied':
            return { ...common, ...empty, status, record: { wins: 5, losses: 0 }, progress: { played: 5, of: 5 }, gameRecord: { won: 10, lost: 2 } };
        case 'dropped':
            return { ...common, ...empty, status, record: { wins: 1, losses: 2 }, progress: { played: 3, of: 5 }, gameRecord: { won: 3, lost: 5 } };
        default:
            return { ...common, ...empty, status: 'idle', event: null, deck: null };
    }
}
