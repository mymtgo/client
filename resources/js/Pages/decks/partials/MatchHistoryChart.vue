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
        <header>
            <strong>Deck winrate</strong>
        </header>
        <!-- Size is IMPORTANT -->
        <ChartContainer :config="chartConfig" class="h-100 w-full mt-4">
            <VisXYContainer :data="chartData">
                <VisLine :x="(d) => d.date" :y="(d) => d.rate" :color="chartConfig.rate.color" />
                <VisAxis type="x" label="Day" :tick-format="formatDay" />
                <VisAxis type="y" label="Winrate %" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>
