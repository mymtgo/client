<script setup lang="ts">
import type { ChartConfig } from '@/components/ui/chart';

import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { TrendingUp } from 'lucide-vue-next';

const description = 'A line chart';

const chartData = [
    { x: 0, y: 0 },
    { x: 1, y: 2 },
    { x: 2, y: 1 },
];

const chartConfig = {
    desktop: {
        label: 'Desktop',
        color: 'var(--chart-1)',
    },
} satisfies ChartConfig;
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Deck winrate</CardTitle>
            <CardDescription>Last 7 days</CardDescription>
        </CardHeader>
        <CardContent>
            <ChartContainer :config="chartConfig" class="h-64">
                <VisXYContainer>
                    <VisLine :data="chartData" :x="(d) => d.x" :y="(d) => d.y" />
                    <VisAxis type="x" />
                    <VisAxis type="y" label="Foo" />
                </VisXYContainer>
                <!--                <VisXYContainer :data="chartData" :margin="{ left: -24 }" :y-domain="[0, undefined]">-->
                <!--                    <VisLine-->
                <!--                        :x="(d: Data) => d.date"-->
                <!--                        :y="(d: Data) => d.desktop"-->
                <!--                        :color="chartConfig.desktop.color"-->
                <!--                        :curve-type="CurveType.Natural"-->
                <!--                    />-->
                <!--                    <VisAxis-->
                <!--                        type="x"-->
                <!--                        :x="(d: Data) => d.date"-->
                <!--                        :tick-line="false"-->
                <!--                        :domain-line="false"-->
                <!--                        :grid-line="false"-->
                <!--                        :num-ticks="6"-->
                <!--                        :tick-format="-->
                <!--                            (d: number) => {-->
                <!--                                const date = new Date(d);-->
                <!--                                return date.toLocaleDateString('en-US', {-->
                <!--                                    month: 'short',-->
                <!--                                });-->
                <!--                            }-->
                <!--                        "-->
                <!--                        :tick-values="chartData.map((d) => d.date)"-->
                <!--                    />-->
                <!--                    <VisAxis type="y" :num-ticks="3" :tick-line="false" :domain-line="false" />-->
                <!--                    <ChartTooltip />-->
                <!--                    <ChartCrosshair-->
                <!--                        :template="componentToString(chartConfig, ChartTooltipContent, { hideLabel: true })"-->
                <!--                        :color="chartConfig.desktop.color"-->
                <!--                    />-->
                <!--                </VisXYContainer>-->
            </ChartContainer>
        </CardContent>
        <CardFooter class="flex-col items-start gap-2 text-sm">
            <div class="flex gap-2 leading-none font-medium">Trending up by 5.2% this month <TrendingUp class="h-4 w-4" /></div>
            <div class="leading-none text-muted-foreground">Showing total visitors for the last 6 months</div>
        </CardFooter>
    </Card>
</template>
