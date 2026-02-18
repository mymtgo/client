<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';

import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';

const props = defineProps<{
    data: any[];
}>();

import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { TrendingUp } from 'lucide-vue-next';
import { map } from 'lodash-es';

type Data = { day: Date; rate: number };

const chartData: Data[] = map(props.data, (data: any) => {
    return {
        date: new Date(data.date),
        rate: parseInt(data.winrate),
    };
});

const chartConfig = {
    rate: {
        label: 'Winrate',
        color: 'var(--chart-1)',
    },
} satisfies ChartConfig;

const formatDay = (ms: number) => new Date(ms).toLocaleDateString('en-GB', { weekday: 'short' });
</script>

<template>
    <div>
        <h3 class="text-sm font-semibold tracking-tight">Deck winrate</h3>
        <!-- Size is IMPORTANT -->
        <ChartContainer :config="chartConfig" class="mt-4 h-[400px] w-full">
            <VisXYContainer :data="chartData">
                <VisLine :x="(d) => d.date" :y="(d) => d.rate" :color="chartConfig.rate.color" />
                <VisAxis type="x" label="Day" :tick-format="formatDay" />
                <VisAxis type="y" label="Winrate %" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>
