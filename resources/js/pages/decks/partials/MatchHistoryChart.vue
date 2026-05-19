<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisCrosshair, VisLine, VisStackedBar, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    data: { date: string; wins: number; losses: number; winrate: string | null }[];
    peer?: { archetypeName: string; deckCount: number; data: { date: string; wins: number; losses: number }[] } | null;
}>();

type ChartMode = 'bars' | 'winrate';
type DataPoint = {
    date: Date;
    wins: number;
    losses: number;
    rate: number | null;
    cumRate: number | null;
    cumWins: number;
    cumLosses: number;
    peerCumRate: number | null;
    peerCumWins: number;
    peerCumLosses: number;
};

const STORAGE_KEY = 'deck:performance-chart-mode';

const chartEl = ref<HTMLElement>();
const winColor = ref('oklch(0.696 0.17 162.48)');
const lossColor = ref('oklch(0.645 0.246 16.439)');
const peerColor = ref('oklch(0.708 0 0)');
const mode = ref<ChartMode>('bars');

onMounted(() => {
    if (chartEl.value) {
        const styles = getComputedStyle(chartEl.value);
        winColor.value = styles.getPropertyValue('--color-success').trim() || winColor.value;
        lossColor.value = styles.getPropertyValue('--color-destructive').trim() || lossColor.value;
        peerColor.value = styles.getPropertyValue('--color-muted-foreground').trim() || peerColor.value;
    }

    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'bars' || stored === 'winrate') {
        mode.value = stored;
    }
});

watch(mode, (value) => {
    localStorage.setItem(STORAGE_KEY, value);
});

const peerByDate = computed(() => {
    const map = new Map<string, { wins: number; losses: number }>();
    props.peer?.data.forEach((d) => {
        map.set(d.date, { wins: d.wins, losses: d.losses });
    });
    return map;
});

const hasPeer = computed(() => Boolean(props.peer && props.peer.data.length > 0));

const chartData = computed<DataPoint[]>(() => {
    let cumWins = 0;
    let cumLosses = 0;
    let lastRate: number | null = null;

    let peerCumWins = 0;
    let peerCumLosses = 0;
    let lastPeerRate: number | null = null;

    return props.data.map((d) => {
        cumWins += d.wins;
        cumLosses += d.losses;
        const total = cumWins + cumLosses;
        const cumRate = total > 0 ? Math.round((cumWins / total) * 100) : lastRate;
        if (cumRate !== null) {
            lastRate = cumRate;
        }

        const peerRow = peerByDate.value.get(d.date);
        if (peerRow) {
            peerCumWins += peerRow.wins;
            peerCumLosses += peerRow.losses;
        }
        const peerTotal = peerCumWins + peerCumLosses;
        const peerCumRate = peerTotal > 0 ? Math.round((peerCumWins / peerTotal) * 100) : lastPeerRate;
        if (peerCumRate !== null) {
            lastPeerRate = peerCumRate;
        }

        return {
            date: new Date(d.date),
            wins: d.wins,
            losses: d.losses,
            rate: d.winrate !== null ? parseInt(d.winrate) : null,
            cumRate,
            cumWins,
            cumLosses,
            peerCumRate,
            peerCumWins,
            peerCumLosses,
        };
    });
});

const hasMatches = (d: DataPoint) => d.wins > 0 || d.losses > 0;

const chartConfig = {
    wins: { label: 'Wins', color: 'var(--color-success)' },
    losses: { label: 'Losses', color: 'var(--color-destructive)' },
} satisfies ChartConfig;

const GAP = 0.1;

const barColorAccessor = (_d: DataPoint, i: number) => {
    if (i === 1) return 'transparent';
    return i === 0 ? winColor.value : lossColor.value;
};

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

const barsTooltipTemplate = (d: DataPoint): string | null => {
    if (!hasMatches(d)) return null;
    const label = d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    return `<div style="padding:8px 12px;line-height:1.5">
        <div style="font-size:11px;opacity:0.6">${label}</div>
        <div style="font-weight:600;font-size:14px">${d.rate !== null ? d.rate + '% win rate' : 'No data'}</div>
        <div style="font-size:12px;opacity:0.8">${d.wins}W - ${d.losses}L</div>
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
    <div style="font-size:11px;opacity:0.7;margin-left:14px">${d.cumWins}W - ${d.cumLosses}L</div>`;

    const peerRow = hasPeer.value && d.peerCumRate !== null
        ? `<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:6px">
            <span style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:0;border-top:2px dashed ${peerColor.value}"></span>${peerLabel.value}</span>
            <span style="font-weight:600">${d.peerCumRate}%</span>
        </div>
        <div style="font-size:11px;opacity:0.7;margin-left:14px">${d.peerCumWins}W - ${d.peerCumLosses}L</div>`
        : '';

    return `<div style="padding:8px 12px;line-height:1.4;min-width:200px">
        <div style="font-size:11px;opacity:0.6;margin-bottom:6px">${label}</div>
        ${deckRow}
        ${peerRow}
    </div>`;
};

const maxTotal = computed(() => {
    const max = Math.max(...chartData.value.map((d) => d.wins + d.losses + ((d.wins > 0 && d.losses > 0) ? GAP : 0)), 1);
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
                        :class="mode === 'bars' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="mode = 'bars'"
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
            <div v-if="mode === 'bars'" class="flex items-center gap-4">
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-success" />
                    Wins
                </span>
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-destructive" />
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
                v-if="mode === 'bars'"
                :data="chartData"
                :y-domain="[0, maxTotal]"
            >
                <VisStackedBar
                    :x="(d: DataPoint) => d.date"
                    :y="[
                        (d: DataPoint) => d.wins,
                        (d: DataPoint) => (d.wins > 0 && d.losses > 0) ? GAP : 0,
                        (d: DataPoint) => d.losses,
                    ]"
                    :color="barColorAccessor"
                    :bar-padding="0.35"
                    :rounded-corners="0"
                />

                <VisCrosshair :template="barsTooltipTemplate" :color="crosshairColorAccessor" />
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
