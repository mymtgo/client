<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisCrosshair, VisLine, VisScatter, VisTooltip, VisXYContainer } from '@unovis/vue';
import { map } from 'lodash-es';
import { computed } from 'vue';

const props = defineProps<{
    data: { date: string; winrate: string | null }[];
    granularity: 'daily' | 'monthly';
}>();

type DataPoint = { date: Date; rate: number | null };

const chartData: DataPoint[] = map(props.data, (d) => ({
    date: new Date(d.date),
    rate: d.winrate !== null ? parseInt(d.winrate) : null,
}));

// Only days where matches were actually played — used for scatter dots
const actualPoints = computed(() => chartData.filter((d) => d.rate !== null));

const chartConfig = {
    rate: {
        label: 'Winrate',
        color: 'var(--chart-1)',
    },
} satisfies ChartConfig;

const formatTick = (ms: number) => {
    if (props.granularity === 'daily') {
        return new Date(ms).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    }
    return new Date(ms).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });
};

const xLabel = props.granularity === 'daily' ? 'Day' : 'Month';

const tooltipTemplate = (d: DataPoint): string | null => {
    if (d.rate === null) return null;
    const label = props.granularity === 'daily'
        ? d.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
        : d.date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    return `<div style="padding:6px 10px;line-height:1.5">
        <div style="font-size:11px;opacity:0.6">${label}</div>
        <div style="font-weight:600;font-size:14px">${d.rate}% win rate</div>
    </div>`;
};
</script>

<template>
    <div>
        <h3 class="text-sm font-semibold tracking-tight">Deck winrate</h3>
        <ChartContainer :config="chartConfig" class="mt-4 h-[400px] w-full">
            <VisXYContainer :data="chartData" :y-domain="[0, 100]">
                <!-- Line breaks at null gaps (no matches played that day/month) -->
                <VisLine
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.rate ?? 0"
                    :defined="(d: DataPoint) => d.rate !== null"
                    :color="chartConfig.rate.color"
                />
                <!-- Dots only on days where matches were actually played -->
                <VisScatter
                    :data="actualPoints"
                    :x="(d: DataPoint) => d.date"
                    :y="(d: DataPoint) => d.rate"
                    :size="() => 8"
                    :color="chartConfig.rate.color"
                />
                <!-- Hover crosshair + tooltip -->
                <VisCrosshair :template="tooltipTemplate" />
                <VisTooltip />
                <VisAxis type="x" :label="xLabel" :tick-format="formatTick" />
                <VisAxis type="y" label="Winrate %" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>
