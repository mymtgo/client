<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisCrosshair, VisLine, VisStackedBar, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
    data: { date: string; wins: number; losses: number; winrate: string | null }[];
}>();

type DataPoint = { date: Date; wins: number; losses: number; rate: number | null };

const lineColor = '#ffffff';
const chartEl = ref<HTMLElement>();
const winColor = ref('#22c55e');
const lossColor = ref('#ef4444');

onMounted(() => {
    if (chartEl.value) {
        const styles = getComputedStyle(chartEl.value);
        winColor.value = styles.getPropertyValue('--color-success').trim() || winColor.value;
        lossColor.value = styles.getPropertyValue('--color-destructive').trim() || lossColor.value;
    }
});

const chartData = computed<DataPoint[]>(() =>
    props.data.map((d) => ({
        date: new Date(d.date),
        wins: d.wins,
        losses: d.losses,
        rate: d.winrate !== null ? parseInt(d.winrate) : null,
    })),
);

const hasMatches = (d: DataPoint) => d.wins > 0 || d.losses > 0;

const chartConfig = {
    wins: { label: 'Wins', color: 'var(--color-success)' },
    losses: { label: 'Losses', color: 'var(--color-destructive)' },
    rate: { label: 'Winrate', color: lineColor },
} satisfies ChartConfig;

const barColorAccessor = (_d: DataPoint, i: number) => {
    return i === 0 ? winColor.value : lossColor.value;
};

const crosshairColorAccessor = (_d: DataPoint, i: number) => {
    return [winColor.value, lossColor.value, lineColor][i] ?? lineColor;
};

const formatTick = (ms: number) => {
    return new Date(ms).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
};

const tooltipTemplate = (d: DataPoint): string | null => {
    if (!hasMatches(d)) return null;
    const label = d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    return `<div style="padding:8px 12px;line-height:1.5">
        <div style="font-size:11px;opacity:0.6">${label}</div>
        <div style="font-weight:600;font-size:14px">${d.rate !== null ? d.rate + '% win rate' : 'No data'}</div>
        <div style="font-size:12px;opacity:0.8">${d.wins}W - ${d.losses}L</div>
    </div>`;
};

const maxTotal = computed(() => Math.max(...chartData.value.map((d) => d.wins + d.losses), 1));
</script>

<template>
    <div ref="chartEl" class="match-history-chart">
        <h3 class="text-sm font-semibold tracking-tight">Performance History</h3>
        <ChartContainer
            :config="chartConfig"
            class="mt-4 h-[400px] w-full"
        >
            <VisXYContainer :data="chartData" :y-domain="[0, maxTotal]">
                <VisStackedBar
                    :x="(d: DataPoint) => d.date"
                    :y="[(d: DataPoint) => d.wins, (d: DataPoint) => d.losses]"
                    :color="barColorAccessor"
                    :bar-padding="0.3"
                    :rounded-corners="2"
                />

                <VisLine
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.rate !== null ? (d.rate / 100) * maxTotal : 0"
                    :defined="(d: DataPoint) => d.rate !== null"
                    :color="lineColor"
                    :line-width="2"
                />

                <VisCrosshair :template="tooltipTemplate" :color="crosshairColorAccessor" />
                <VisTooltip />
                <VisAxis type="x" :tick-format="formatTick" />
                <VisAxis type="y" label="W/L" />
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
</style>
