<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisCrosshair, VisLine, VisScatter, VisStackedBar, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';

const props = defineProps<{
    data: { date: string; wins: number; losses: number; winrate: string | null }[];
}>();

type DataPoint = { date: Date; wins: number; losses: number; rate: number | null };

const chartData = computed<DataPoint[]>(() =>
    props.data.map((d) => ({
        date: new Date(d.date),
        wins: d.wins,
        losses: d.losses,
        rate: d.winrate !== null ? parseInt(d.winrate) : null,
    })),
);

const hasMatches = (d: DataPoint) => d.wins > 0 || d.losses > 0;
const actualPoints = computed(() => chartData.value.filter((d) => d.rate !== null));

const chartConfig = {
    wins: { label: 'Wins', color: 'var(--color-success)' },
    losses: { label: 'Losses', color: 'var(--color-destructive)' },
    rate: { label: 'Winrate', color: 'var(--chart-1)' },
} satisfies ChartConfig;

const barColorAccessor = (_d: DataPoint, i: number) => {
    return i === 0 ? chartConfig.wins.color : chartConfig.losses.color;
};

const formatTick = (ms: number) => {
    return new Date(ms).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
};

const tooltipTemplate = (d: DataPoint): string | null => {
    if (!hasMatches(d)) return null;
    const label = d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    return `<div style="padding:6px 10px;line-height:1.5">
        <div style="font-size:11px;opacity:0.6">${label}</div>
        <div style="font-weight:600;font-size:14px">${d.rate !== null ? d.rate + '% win rate' : 'No data'}</div>
        <div style="font-size:12px;opacity:0.8">${d.wins}W - ${d.losses}L</div>
    </div>`;
};

const maxTotal = computed(() => Math.max(...chartData.value.map((d) => d.wins + d.losses), 1));
</script>

<template>
    <div>
        <h3 class="text-sm font-semibold tracking-tight">Performance History</h3>
        <ChartContainer :config="chartConfig" class="mt-4 h-[400px] w-full">
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
                    :color="chartConfig.rate.color"
                />
                <VisScatter
                    :data="actualPoints"
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.rate !== null ? (d.rate / 100) * maxTotal : 0"
                    :size="() => 8"
                    :color="chartConfig.rate.color"
                />

                <VisCrosshair :template="tooltipTemplate" />
                <VisTooltip />
                <VisAxis type="x" :tick-format="formatTick" />
                <VisAxis type="y" label="W/L" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>
