<script setup lang="ts">
import { computed } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';

type FormatChart = {
    months: string[];
    formats: string[];
    data: ({ x: number } & Record<string, number | null>)[];
};

const props = defineProps<{
    formatChart: FormatChart;
}>();

const chartColors = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

const formats = computed(() =>
    props.formatChart.formats.map((key, i) => ({
        key,
        color: chartColors[i % chartColors.length],
        label: key,
    })),
);

const chartConfig = computed(() =>
    Object.fromEntries(
        formats.value.map((f) => [f.key, { label: f.label, color: f.color }]),
    ) as ChartConfig,
);

const tickFormat = (i: number) => props.formatChart.months[i] ?? '';
const yTickFormat = (v: number) => `${v}%`;
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <div class="flex items-center justify-between">
                <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">Format Performance</CardTitle>
                <!-- Legend -->
                <div class="flex items-center gap-4">
                    <div v-for="f in formats" :key="f.key" class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full" :style="{ backgroundColor: f.color }" />
                        <span class="text-xs text-muted-foreground">{{ f.label }}</span>
                    </div>
                </div>
            </div>
        </CardHeader>

        <CardContent>
            <ChartContainer :config="chartConfig" class="h-[220px] w-full">
                <VisXYContainer :data="formatChart.data">
                    <VisLine
                        v-for="f in formats"
                        :key="f.key"
                        :x="(d: Record<string, number | null>) => d.x"
                        :y="(d: Record<string, number | null>) => d[f.key]"
                        :color="f.color"
                        :line-width="2"
                    />
                    <VisAxis type="x" :tick-format="tickFormat" />
                    <VisAxis type="y" :tick-format="yTickFormat" :domain-min="30" :domain-max="100" />
                </VisXYContainer>
            </ChartContainer>
        </CardContent>
    </Card>
</template>
