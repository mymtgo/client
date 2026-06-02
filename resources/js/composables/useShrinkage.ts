import { computed, type ComputedRef, type MaybeRefOrGetter, toValue } from 'vue';
import { perspectiveAdjust, rawRate, SHRINK_KEYS, type ShrinkKey, shrunkRate } from '@/lib/stats/shrinkage';

export interface ShrinkableCardStat {
    readonly keptWon: number;
    readonly keptLost: number;
    readonly castWon: number;
    readonly castLost: number;
    readonly seenWon: number;
    readonly seenLost: number;
    readonly pregameWon: number;
    readonly pregameLost: number;
}

type Rates = Readonly<Record<ShrinkKey, number>>;
type SampleSizes = Readonly<Record<ShrinkKey, number>>;
type RawRates = Readonly<Record<ShrinkKey, number | null>>;

export interface ShrunkStat<T extends ShrinkableCardStat> {
    readonly raw: T;
    readonly samples: SampleSizes;
    readonly rawRates: RawRates;
    readonly shrunk: Rates;
}

interface UseShrinkageOptions<T extends ShrinkableCardStat> {
    stats: MaybeRefOrGetter<readonly T[]>;
    prior: MaybeRefOrGetter<number>;
    strength: MaybeRefOrGetter<number>;
    perspective: MaybeRefOrGetter<'mine' | 'theirs'>;
}

function winsFor(stat: ShrinkableCardStat, key: ShrinkKey): number {
    switch (key) {
        case 'kept': return stat.keptWon;
        case 'cast': return stat.castWon;
        case 'seen': return stat.seenWon;
        case 'pregame': return stat.pregameWon;
    }
}

function lossesFor(stat: ShrinkableCardStat, key: ShrinkKey): number {
    switch (key) {
        case 'kept': return stat.keptLost;
        case 'cast': return stat.castLost;
        case 'seen': return stat.seenLost;
        case 'pregame': return stat.pregameLost;
    }
}

export function useShrinkage<T extends ShrinkableCardStat>(
    options: UseShrinkageOptions<T>,
): ComputedRef<ShrunkStat<T>[]> {
    return computed<ShrunkStat<T>[]>(() => {
        const stats = toValue(options.stats);
        const prior = toValue(options.prior);
        const strength = toValue(options.strength);
        const perspective = toValue(options.perspective);

        return stats.map((stat) => {
            const samples = {} as Record<ShrinkKey, number>;
            const raws = {} as Record<ShrinkKey, number | null>;
            const shrunk = {} as Record<ShrinkKey, number>;

            for (const key of SHRINK_KEYS) {
                const wins = winsFor(stat, key);
                const losses = lossesFor(stat, key);
                const games = wins + losses;

                samples[key] = games;
                const raw = rawRate(wins, games);
                raws[key] = raw === null ? null : perspectiveAdjust(raw, perspective);

                const s = shrunkRate({ wins, games, prior, strength });
                shrunk[key] = perspectiveAdjust(s, perspective);
            }

            return {
                raw: stat,
                samples: samples as Readonly<typeof samples>,
                rawRates: raws as Readonly<typeof raws>,
                shrunk: shrunk as Readonly<typeof shrunk>,
            } satisfies ShrunkStat<T>;
        });
    });
}
