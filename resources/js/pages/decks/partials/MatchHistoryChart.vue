<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer } from '@/components/ui/chart';
import { formatMatchRecord } from '@/lib/matchRecord';
import { parseLocalDate } from '@/lib/utils';
import { VisAxis, VisCrosshair, VisLine, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    data: { date: string; wins: number; losses: number; draws: number; winrate: string | null }[];
    peer?: { archetypeName: string; deckCount: number; data: { date: string; wins: number; losses: number; draws: number }[] } | null;
    timeframe: string;
}>();

type ChartMode = 'counts' | 'winrate';
type DataPoint = {
    date: Date;
    endDate: Date;
    wins: number;
    losses: number;
    draws: number;
    rate: number | null;
    cumRate: number | null;
    cumWins: number;
    cumLosses: number;
    cumDraws: number;
    peerCumRate: number | null;
    peerCumWins: number;
    peerCumLosses: number;
    peerCumDraws: number;
};

const STORAGE_KEY = 'deck:performance-chart-mode';

const chartEl = ref<HTMLElement>();
const winColor = ref('oklch(0.696 0.17 162.48)');
const lossColor = ref('oklch(0.645 0.246 16.439)');
const peerColor = ref('oklch(0.708 0 0)');
const mode = ref<ChartMode>('counts');

onMounted(() => {
    if (chartEl.value) {
        const styles = getComputedStyle(chartEl.value);
        winColor.value = styles.getPropertyValue('--color-success').trim() || winColor.value;
        lossColor.value = styles.getPropertyValue('--color-destructive').trim() || lossColor.value;
        peerColor.value = styles.getPropertyValue('--color-muted-foreground').trim() || peerColor.value;
    }

    // 'bars' is what this mode was called when it drew stacked bars; existing
    // preferences still carry it.
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'bars' || stored === 'counts') {
        mode.value = 'counts';
    } else if (stored === 'winrate') {
        mode.value = 'winrate';
    }
});

watch(mode, (value) => {
    localStorage.setItem(STORAGE_KEY, value);
});

const peerByDate = computed(() => {
    const map = new Map<string, { wins: number; losses: number; draws: number }>();
    props.peer?.data.forEach((d) => {
        map.set(d.date, { wins: d.wins, losses: d.losses, draws: d.draws });
    });
    return map;
});

const hasPeer = computed(() => Boolean(props.peer && props.peer.data.length > 0));

/**
 * Days per plotted point.
 *
 * The range picker at the top of the page already says how much history is on
 * screen, so the chart follows it rather than adding a second control. Lines
 * need far fewer points than bars did: a day with no matches is an invisible
 * bar but a visible spike to the floor, and an all-time view is mostly empty
 * days, because the series carries a row for every date between the first
 * match and the last.
 */
const bucketDays = computed(() => {
    if (['week', 'biweekly', 'monthly'].includes(props.timeframe)) {
        return 1;
    }

    if (props.timeframe === 'year') {
        return 7;
    }

    // All time covers anything from a week-old deck to several years, so the
    // span of the data decides rather than the label.
    if (props.data.length <= 60) return 1;
    if (props.data.length <= 400) return 7;

    return 30;
});

type DailyRow = { date: string; wins: number; losses: number; draws: number; peer: { wins: number; losses: number; draws: number } | null };

/** Deck rows with the matching peer row attached, before any bucketing. */
const dailyRows = computed<DailyRow[]>(() =>
    props.data.map((d) => ({
        date: d.date,
        wins: d.wins,
        losses: d.losses,
        draws: d.draws,
        peer: peerByDate.value.get(d.date) ?? null,
    })),
);

const bucketedRows = computed(() => {
    const size = bucketDays.value;
    const buckets: { date: string; endDate: string; wins: number; losses: number; draws: number; peer: { wins: number; losses: number; draws: number } | null }[] = [];

    for (let i = 0; i < dailyRows.value.length; i += size) {
        const slice = dailyRows.value.slice(i, i + size);
        const peerRows = slice.filter((row) => row.peer !== null);

        buckets.push({
            date: slice[0].date,
            endDate: slice[slice.length - 1].date,
            wins: slice.reduce((sum, row) => sum + row.wins, 0),
            losses: slice.reduce((sum, row) => sum + row.losses, 0),
            draws: slice.reduce((sum, row) => sum + row.draws, 0),
            peer: peerRows.length
                ? {
                    wins: peerRows.reduce((sum, row) => sum + (row.peer?.wins ?? 0), 0),
                    losses: peerRows.reduce((sum, row) => sum + (row.peer?.losses ?? 0), 0),
                    draws: peerRows.reduce((sum, row) => sum + (row.peer?.draws ?? 0), 0),
                }
                : null,
        });
    }

    return buckets;
});

const chartData = computed<DataPoint[]>(() => {
    let cumWins = 0;
    let cumLosses = 0;
    let cumDraws = 0;
    let lastRate: number | null = null;

    let peerCumWins = 0;
    let peerCumLosses = 0;
    let peerCumDraws = 0;
    let lastPeerRate: number | null = null;

    // Draws count as matches played, matching the record and every other winrate.
    return bucketedRows.value.map((d) => {
        cumWins += d.wins;
        cumLosses += d.losses;
        cumDraws += d.draws;
        const total = cumWins + cumLosses + cumDraws;
        const cumRate = total > 0 ? Math.round((cumWins / total) * 100) : lastRate;
        if (cumRate !== null) {
            lastRate = cumRate;
        }

        const peerRow = d.peer;
        if (peerRow) {
            peerCumWins += peerRow.wins;
            peerCumLosses += peerRow.losses;
            peerCumDraws += peerRow.draws;
        }
        const peerTotal = peerCumWins + peerCumLosses + peerCumDraws;
        const peerCumRate = peerTotal > 0 ? Math.round((peerCumWins / peerTotal) * 100) : lastPeerRate;
        if (peerCumRate !== null) {
            lastPeerRate = peerCumRate;
        }

        const bucketTotal = d.wins + d.losses + d.draws;

        return {
            date: parseLocalDate(d.date),
            endDate: parseLocalDate(d.endDate),
            wins: d.wins,
            losses: d.losses,
            draws: d.draws,
            rate: bucketTotal > 0 ? Math.round((d.wins / bucketTotal) * 100) : null,
            cumRate,
            cumWins,
            cumLosses,
            cumDraws,
            peerCumRate,
            peerCumWins,
            peerCumLosses,
            peerCumDraws,
        };
    });
});

const hasMatches = (d: DataPoint) => d.wins > 0 || d.losses > 0 || d.draws > 0;

const chartConfig = {
    wins: { label: 'Wins', color: 'var(--color-success)' },
    losses: { label: 'Losses', color: 'var(--color-destructive)' },
} satisfies ChartConfig;

const crosshairColorAccessor = (_d: DataPoint, i: number) => {
    return [winColor.value, lossColor.value][i] ?? winColor.value;
};

const lineColor = computed(() => winColor.value);
const winrateCrosshairColors = computed(() =>
    hasPeer.value ? [winColor.value, peerColor.value] : [winColor.value],
);

const formatTick = (ms: number) => {
    return new Date(ms).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
};

const formatPercentTick = (value: number) => `${value}%`;

const formatBucketLabel = (d: DataPoint): string => {
    const start = d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

    if (d.endDate.getTime() === d.date.getTime()) {
        return start;
    }

    const end = d.endDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

    return `${start} \u2013 ${end}`;
};

const countsTooltipTemplate = (d: DataPoint): string | null => {
    if (!hasMatches(d)) return null;
    const label = formatBucketLabel(d);
    return `<div style="padding:8px 12px;line-height:1.5">
        <div style="font-size:11px;opacity:0.6">${label}</div>
        <div style="font-weight:600;font-size:14px">${d.rate !== null ? d.rate + '% win rate' : 'No data'}</div>
        <div style="font-size:12px;opacity:0.8">${formatMatchRecord(d.wins, d.losses, d.draws)}</div>
    </div>`;
};

const peerLabel = computed(() => {
    if (!props.peer) return '';
    const noun = props.peer.deckCount === 1 ? 'deck' : 'decks';
    return `Other ${props.peer.archetypeName} ${noun} (${props.peer.deckCount})`;
});

const winrateTooltipTemplate = (d: DataPoint): string | null => {
    if (d.cumRate === null) return null;
    const label = d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const deckRow = `<div style="display:flex;justify-content:space-between;gap:16px;align-items:center">
        <span style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:9999px;background:${winColor.value}"></span>This deck</span>
        <span style="font-weight:600">${d.cumRate}%</span>
    </div>
    <div style="font-size:11px;opacity:0.7;margin-left:14px">${formatMatchRecord(d.cumWins, d.cumLosses, d.cumDraws)}</div>`;

    const peerRow = hasPeer.value && d.peerCumRate !== null
        ? `<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:6px">
            <span style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:0;border-top:2px dashed ${peerColor.value}"></span>${peerLabel.value}</span>
            <span style="font-weight:600">${d.peerCumRate}%</span>
        </div>
        <div style="font-size:11px;opacity:0.7;margin-left:14px">${formatMatchRecord(d.peerCumWins, d.peerCumLosses, d.peerCumDraws)}</div>`
        : '';

    return `<div style="padding:8px 12px;line-height:1.4;min-width:200px">
        <div style="font-size:11px;opacity:0.6;margin-bottom:6px">${label}</div>
        ${deckRow}
        ${peerRow}
    </div>`;
};

const maxTotal = computed(() => {
    const max = Math.max(...chartData.value.flatMap((d) => [d.wins, d.losses]), 1);
    return Math.ceil(max);
});
</script>

<template>
    <div ref="chartEl" class="match-history-chart">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-semibold tracking-tight">Performance History</h3>
                <div class="inline-flex items-center rounded-md border border-border bg-muted/40 p-0.5 text-xs">
                    <button
                        type="button"
                        class="rounded px-2 py-0.5 transition-colors"
                        :class="mode === 'counts' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="mode = 'counts'"
                    >
                        Wins/Losses
                    </button>
                    <button
                        type="button"
                        class="rounded px-2 py-0.5 transition-colors"
                        :class="mode === 'winrate' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="mode = 'winrate'"
                    >
                        W/R over time
                    </button>
                </div>
            </div>
            <div v-if="mode === 'counts'" class="flex items-center gap-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="inline-block h-0.5 w-3 bg-success" />
                    Wins
                </span>
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="inline-block h-0.5 w-3 bg-destructive" />
                    Losses
                </span>
            </div>
            <div v-else class="flex items-center gap-4 text-xs text-muted-foreground">
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-0.5 w-3 bg-success" />
                    This deck
                </span>
                <span v-if="hasPeer" class="flex items-center gap-1.5">
                    <span class="peer-legend-dash inline-block h-0 w-3" />
                    {{ peerLabel }}
                </span>
            </div>
        </div>
        <ChartContainer
            :config="chartConfig"
            class="mt-4 h-[400px] w-full"
        >
            <VisXYContainer
                v-if="mode === 'counts'"
                :data="chartData"
                :y-domain="[0, maxTotal]"
            >
                <VisLine
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.wins"
                    :color="winColor"
                    :line-width="2"
                />
                <VisLine
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.losses"
                    :color="lossColor"
                    :line-width="2"
                />

                <VisCrosshair :template="countsTooltipTemplate" :color="crosshairColorAccessor" />
                <VisTooltip />
                <VisAxis type="x" :tick-format="formatTick" />
                <VisAxis type="y" :grid-line="true" />
            </VisXYContainer>
            <VisXYContainer
                v-else
                :data="chartData"
                :y-domain="[0, 100]"
            >
                <VisLine
                    v-if="hasPeer"
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.peerCumRate"
                    :color="peerColor"
                    :line-width="2"
                    :line-dash-array="[4, 4]"
                />
                <VisLine
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.cumRate"
                    :color="lineColor"
                    :line-width="2"
                />

                <VisCrosshair :template="winrateTooltipTemplate" :color="winrateCrosshairColors" />
                <VisTooltip />
                <VisAxis type="x" :tick-format="formatTick" />
                <VisAxis type="y" :grid-line="true" :tick-format="formatPercentTick" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>

<style>
.match-history-chart [data-slot="chart"] {
    --vis-tooltip-background-color: hsl(var(--popover)) !important;
    --vis-tooltip-text-color: hsl(var(--popover-foreground)) !important;
    --vis-tooltip-border-color: hsl(var(--border)) !important;
    --vis-tooltip-border-radius: 8px !important;
}

.match-history-chart .vis-axis-grid-line line {
    stroke: var(--color-border);
    stroke-dasharray: 4 4;
    stroke-opacity: 0.5;
}

.match-history-chart .vis-axis .tick line {
    stroke: var(--color-border);
    stroke-opacity: 0.3;
}

.match-history-chart .peer-legend-dash {
    border-top: 2px dashed var(--color-muted-foreground);
}
</style>
