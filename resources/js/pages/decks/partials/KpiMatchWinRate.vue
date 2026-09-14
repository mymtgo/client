<script setup lang="ts">
import KpiCard from '@/pages/decks/partials/KpiCard.vue';
import { computed } from 'vue';

const props = defineProps<{
    matchRecord: App.Data.Front.MatchRecordData;
    winrateDelta: { previousRate: number | null; previousTotal: number; delta: number | null };
    chartData: { date: string; wins: number; losses: number; draws: number; winrate: string | null }[];
    timeframeLabel: string;
}>();

/** Days of the rolling window the sparkline smooths over. */
const WINDOW_DAYS = 7;

const tone = computed(() => {
    if (props.matchRecord.winrate > 50) return 'text-success';
    if (props.matchRecord.winrate < 50) return 'text-destructive';
    return '';
});

/**
 * Win rate over a trailing window, plotted only for days that had matches.
 * A daily rate would be mostly noise on a deck played a few times a night.
 */
const series = computed(() => {
    const points: number[] = [];

    props.chartData.forEach((_, index) => {
        const window = props.chartData.slice(Math.max(0, index - WINDOW_DAYS + 1), index + 1);
        const wins = window.reduce((sum, d) => sum + d.wins, 0);
        const losses = window.reduce((sum, d) => sum + d.losses, 0);
        const draws = window.reduce((sum, d) => sum + d.draws, 0);
        const total = wins + losses + draws;

        if (total > 0) {
            points.push((wins / total) * 100);
        }
    });

    return points;
});

/** Points for a 100x32 viewBox, flipped so a higher win rate sits higher. */
const path = computed(() => {
    if (series.value.length < 2) return null;

    const step = 100 / (series.value.length - 1);

    return series.value.map((rate, index) => `${(index * step).toFixed(2)},${(32 - (rate / 100) * 32).toFixed(2)}`).join(' ');
});

const deltaTone = computed(() => {
    if (props.winrateDelta.delta === null) return '';
    if (props.winrateDelta.delta > 0) return 'bg-success/15 text-success';
    if (props.winrateDelta.delta < 0) return 'bg-destructive/15 text-destructive';
    return 'bg-muted text-muted-foreground';
});
</script>

<template>
    <KpiCard label="Match win rate" :scope="timeframeLabel">
        <div class="flex items-center gap-2.5">
            <span class="text-4xl font-bold tabular-nums" :class="tone">{{ matchRecord.winrate }}%</span>
            <span
                v-if="winrateDelta.delta !== null"
                class="rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums"
                :class="deltaTone"
            >
                {{ winrateDelta.delta > 0 ? '▲' : winrateDelta.delta < 0 ? '▼' : '' }}
                {{ Math.abs(winrateDelta.delta) }} pts
            </span>
        </div>

        <p class="text-sm text-muted-foreground tabular-nums">
            {{ matchRecord.label }}
            <template v-if="winrateDelta.previousRate !== null">
                <span class="mx-1">&middot;</span>
                {{ winrateDelta.previousRate }}% previous {{ timeframeLabel.toLowerCase() }}
            </template>
        </p>

        <svg v-if="path" class="h-8 w-full" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true">
            <line x1="0" y1="16" x2="100" y2="16" stroke="currentColor" stroke-width="0.5" stroke-dasharray="2 2" class="text-muted-foreground/40" />
            <polyline
                :points="path"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                vector-effect="non-scaling-stroke"
                stroke-linejoin="round"
                stroke-linecap="round"
                :class="matchRecord.winrate >= 50 ? 'text-success' : 'text-destructive'"
            />
        </svg>
    </KpiCard>
</template>
