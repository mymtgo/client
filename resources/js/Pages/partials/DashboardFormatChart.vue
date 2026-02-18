<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';

// FAKE DATA — replace with props from backend
// Each entry is one month, with a win rate (0–100) per format played
type DataPoint = {
    x: number;
    Modern: number | null;
    Pioneer: number | null;
    Legacy: number | null;
};

const months = ['Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb'];

const data: DataPoint[] = [
    { x: 0, Modern: 55, Pioneer: 60, Legacy: null },
    { x: 1, Modern: 61, Pioneer: 52, Legacy: 48 },
    { x: 2, Modern: 58, Pioneer: 58, Legacy: 52 },
    { x: 3, Modern: 64, Pioneer: 55, Legacy: 45 },
    { x: 4, Modern: 67, Pioneer: 61, Legacy: 55 },
    { x: 5, Modern: 63, Pioneer: 57, Legacy: 58 },
];

const formats: { key: keyof Omit<DataPoint, 'x'>; color: string; label: string }[] = [
    { key: 'Modern', color: 'var(--chart-1)', label: 'Modern' },
    { key: 'Pioneer', color: 'var(--chart-2)', label: 'Pioneer' },
    { key: 'Legacy', color: 'var(--chart-3)', label: 'Legacy' },
];

const chartConfig = {
    Modern: { label: 'Modern', color: 'var(--chart-1)' },
    Pioneer: { label: 'Pioneer', color: 'var(--chart-2)' },
    Legacy: { label: 'Legacy', color: 'var(--chart-3)' },
} satisfies ChartConfig;

const tickFormat = (i: number) => months[i] ?? '';
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
                <VisXYContainer :data="data">
                    <VisLine
                        v-for="f in formats"
                        :key="f.key"
                        :x="(d: DataPoint) => d.x"
                        :y="(d: DataPoint) => d[f.key]"
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
