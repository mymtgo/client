<script setup lang="ts">
import { ChartContainer } from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { formatMatchRecord } from '@/lib/matchRecord';
import { escapeHtml } from '@/lib/utils';
import type { MatchupHistoryEntry } from '@/types/decks';
import { VisAxis, VisCrosshair, VisLine, VisScatter, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
    /** Match history as served by the drawer: newest first. */
    history: MatchupHistoryEntry[];
}>();

type TrendPoint = {
    index: number;
    date: Date;
    dateFormatted: string;
    opponentName: string | null;
    score: string;
    outcome: MatchupHistoryEntry['outcome'];
    cumWins: number;
    cumLosses: number;
    cumDraws: number;
    cumRate: number;
};

const chartEl = ref<HTMLElement>();
const winColor = ref('oklch(0.696 0.17 162.48)');
const lossColor = ref('oklch(0.645 0.246 16.439)');
const neutralColor = ref('oklch(0.708 0 0)');

onMounted(() => {
    if (chartEl.value) {
        const styles = getComputedStyle(chartEl.value);
        winColor.value = styles.getPropertyValue('--color-success').trim() || winColor.value;
        lossColor.value = styles.getPropertyValue('--color-destructive').trim() || lossColor.value;
        neutralColor.value = styles.getPropertyValue('--color-muted-foreground').trim() || neutralColor.value;
    }
});

/**
 * Cumulative win rate walked oldest to newest. The x axis is the match
 * sequence rather than the calendar: a handful of matches spread over months
 * clump on a date axis and the gaps carry no meaning.
 *
 * Draws count as matches played, matching the record and every other winrate.
 */
const points = computed<TrendPoint[]>(() => {
    let cumWins = 0;
    let cumLosses = 0;
    let cumDraws = 0;

    return [...props.history].reverse().map((match, index) => {
        if (match.outcome === 'win') cumWins += 1;
        else if (match.outcome === 'loss') cumLosses += 1;
        else if (match.outcome === 'draw') cumDraws += 1;

        const total = cumWins + cumLosses + cumDraws;

        return {
            index,
            date: new Date(match.date),
            dateFormatted: match.dateFormatted,
            opponentName: match.opponentName,
            score: match.score,
            outcome: match.outcome,
            cumWins,
            cumLosses,
            cumDraws,
            cumRate: total > 0 ? Math.round((cumWins / total) * 100) : 0,
        };
    });
});

const hasEnoughData = computed(() => points.value.length >= 2);

const chartConfig = {
    winrate: { label: 'Win rate', color: 'var(--color-success)' },
} satisfies ChartConfig;

const outcomeColor = (d: TrendPoint): string => {
    if (d.outcome === 'win') return winColor.value;
    if (d.outcome === 'loss') return lossColor.value;
    return neutralColor.value;
};

/** Only label a handful of matches so dates stay legible in a narrow drawer. */
const tickValues = computed<number[]>(() => {
    const count = points.value.length;
    const maxTicks = 6;
    if (count <= maxTicks) {
        return points.value.map((p) => p.index);
    }

    const step = Math.ceil((count - 1) / (maxTicks - 1));
    const ticks: number[] = [];
    for (let i = 0; i < count; i += step) {
        ticks.push(i);
    }
    if (ticks[ticks.length - 1] !== count - 1) {
        ticks.push(count - 1);
    }
    return ticks;
});

const formatTick = (index: number): string => points.value[index]?.dateFormatted ?? '';

const formatPercentTick = (value: number) => `${value}%`;

const outcomeLabel = (d: TrendPoint): string => {
    if (d.outcome === 'win') return 'Win';
    if (d.outcome === 'loss') return 'Loss';
    if (d.outcome === 'draw') return 'Draw';
    return 'Unknown';
};

const tooltipTemplate = (d: TrendPoint): string => {
    const dateLabel = d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const opponent = escapeHtml(d.opponentName ?? 'Unknown opponent');

    return `<div style="padding:8px 12px;line-height:1.4;min-width:180px">
        <div style="font-size:11px;opacity:0.6">${dateLabel}</div>
        <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:4px">
            <span style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:9999px;background:${outcomeColor(d)}"></span>${opponent}</span>
            <span style="font-weight:600">${outcomeLabel(d)} ${escapeHtml(d.score)}</span>
        </div>
        <div style="font-size:11px;opacity:0.7;margin-top:6px">After this match: <strong>${d.cumRate}%</strong> (${formatMatchRecord(d.cumWins, d.cumLosses, d.cumDraws)})</div>
    </div>`;
};
</script>

<template>
    <div ref="chartEl" class="matchup-trend-chart">
        <div v-if="!hasEnoughData" class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
            Need at least two matches to show a trend.
        </div>
        <ChartContainer v-else :config="chartConfig" class="h-[140px] w-full">
            <VisXYContainer :data="points" :y-domain="[0, 100]" :x-domain="[-0.5, points.length - 0.5]" :margin="{ top: 8, right: 8, bottom: 0, left: 0 }">
                <VisLine
                    :x="(d: TrendPoint) => d.index"
                    :y="() => 50"
                    :color="neutralColor"
                    :line-width="1"
                    :line-dash-array="[4, 4]"
                />
                <VisLine
                    :x="(d: TrendPoint) => d.index"
                    :y="(d: TrendPoint) => d.cumRate"
                    :color="winColor"
                    :line-width="2"
                />
                <VisScatter
                    :x="(d: TrendPoint) => d.index"
                    :y="(d: TrendPoint) => d.cumRate"
                    :color="outcomeColor"
                    :size="7"
                />

                <VisCrosshair :template="tooltipTemplate" :color="outcomeColor" :hide-when-far-from-pointer="false" />
                <VisTooltip />
                <VisAxis type="x" :tick-values="tickValues" :tick-format="formatTick" :num-ticks="tickValues.length" />
                <VisAxis type="y" :grid-line="true" :tick-format="formatPercentTick" :tick-values="[0, 50, 100]" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>

<style>
.matchup-trend-chart [data-slot="chart"] {
    --vis-tooltip-background-color: hsl(var(--popover)) !important;
    --vis-tooltip-text-color: hsl(var(--popover-foreground)) !important;
    --vis-tooltip-border-color: hsl(var(--border)) !important;
    --vis-tooltip-border-radius: 8px !important;
}

.matchup-trend-chart .vis-axis-grid-line line {
    stroke: var(--color-border);
    stroke-dasharray: 4 4;
    stroke-opacity: 0.5;
}

.matchup-trend-chart .vis-axis .tick line {
    stroke: var(--color-border);
    stroke-opacity: 0.3;
}
</style>
