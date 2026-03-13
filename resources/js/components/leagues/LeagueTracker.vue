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
                <span class="inline-flex items-center gap-1.5">
                    <span v-if="league.hasActiveMatch" class="inline-flex items-center gap-1">
                        <template v-for="(game, i) in league.games" :key="i">
                            <span
                                class="inline-block size-2.5 rounded-full"
                                :class="{
                                    'bg-green-500': game.won === true,
                                    'bg-red-500': game.won === false && game.ended,
                                    'border border-white/40 bg-transparent': !game.ended,
                                }"
                            />
                        </template>
                    </span>
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
