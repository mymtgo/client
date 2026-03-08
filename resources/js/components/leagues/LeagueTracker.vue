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
        bgColor?: string;
    }>(),
    {
        font: 'Segoe UI',
        textColor: '#ffffff',
        bgColor: '#1a1a1a',
    },
);
</script>

<template>
    <div
        class="flex flex-col justify-center px-4 py-3"
        :style="{
            fontFamily: font,
            color: textColor,
            backgroundColor: bgColor,
        }"
    >
        <template v-if="league">
            <div class="flex items-baseline justify-between">
                <span class="truncate text-sm font-semibold">
                    {{ league.deckName ?? 'Unknown Deck' }}
                </span>
                <span class="ml-2 shrink-0 text-lg font-bold tabular-nums">
                    {{ league.wins }}-{{ league.losses }}
                </span>
            </div>
            <div class="flex items-center justify-between text-xs" :style="{ color: textColor, opacity: 0.7 }">
                <span class="inline-flex items-center gap-1">
                    {{ league.format }}
                    <PhantomBadge v-if="league.phantom" :label="false" />
                </span>
                <span class="inline-flex items-center gap-1.5">
                    Match {{ league.totalMatches + (league.hasActiveMatch ? 0 : 1) }}
                    <span v-if="league.hasActiveMatch" class="inline-flex items-center gap-1">
                        <template v-for="(game, i) in league.games" :key="i">
                            <span
                                class="inline-block size-2 rounded-full"
                                :class="{
                                    'bg-green-500': game.won === true,
                                    'bg-red-500': game.won === false && game.ended,
                                    'animate-pulse bg-yellow-400': !game.ended,
                                }"
                            />
                        </template>
                    </span>
                </span>
            </div>
        </template>
        <template v-else>
            <p class="text-center text-xs" :style="{ color: textColor, opacity: 0.7 }">
                Start or continue a league to track
            </p>
        </template>
    </div>
</template>
