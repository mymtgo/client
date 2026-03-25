<script setup lang="ts">
import PhantomBadge from '@/components/leagues/PhantomBadge.vue';

export interface LeagueData {
    id: number;
    name: string;
    format: string;
    phantom: boolean;
    wins: number;
    losses: number;
    totalMatches: number;
    deckName: string | null;
    hasActiveMatch: boolean;
    games: Array<{ won: boolean | null; ended: boolean }>;
}

const props = withDefaults(
    defineProps<{
        league: LeagueData | null;
        font?: string;
        textColor?: string;
    }>(),
    {
        font: 'sans-serif',
        textColor: '#ffffff',
    },
);

function gameDotClass(game: { won: boolean | null; ended: boolean } | undefined) {
    if (!game) return 'border-2 border-white/20 bg-transparent';
    if (game.won === true) return 'bg-green-500';
    if (game.won === false) return 'bg-red-500';

    // Game exists but no result yet — pulse if actively in progress
    if (!game.ended) return 'animate-pulse border-2 border-white/70 bg-transparent';

    // Ended but result not yet resolved
    return 'border-2 border-white/40 bg-transparent';
}
</script>

<template>
    <div
        class="flex h-full flex-col justify-center px-4 py-3"
        :style="{
            fontFamily: font,
            color: textColor,
        }"
    >
        <template v-if="league">
            <div class="flex items-baseline justify-between">
                <span class="truncate text-lg font-semibold">
                    {{ league.deckName ?? 'Unknown Deck' }}
                </span>
                <span class="ml-2 shrink-0 text-2xl font-bold tabular-nums">
                    {{ league.wins }}-{{ league.losses }}
                </span>
            </div>
            <div class="flex items-center justify-between text-base" :style="{ color: textColor, opacity: 0.7 }">
                <span class="inline-flex items-center gap-1">
                    {{ league.format }}
                    <PhantomBadge v-if="league.phantom" :label="false" />
                </span>
                <span v-if="league.hasActiveMatch" class="inline-flex items-center gap-1">
                    <span
                        v-for="i in 3"
                        :key="i"
                        class="inline-block size-3 rounded-full"
                        :class="gameDotClass(league.games[i - 1])"
                    />
                </span>
            </div>
        </template>
        <template v-else>
            <p class="text-center text-sm" :style="{ color: textColor, opacity: 0.7 }">
                Start or continue a league to track
            </p>
        </template>
    </div>
</template>
