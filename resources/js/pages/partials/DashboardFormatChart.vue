<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BarChart3 } from 'lucide-vue-next';

type FormatStat = {
    format: string;
    wins: number;
    losses: number;
    total: number;
    winrate: number;
};

defineProps<{
    formatChart: FormatStat[];
}>();

function winrateClass(pct: number): string {
    if (pct >= 55) return 'text-success';
    if (pct < 45) return 'text-destructive';
    return '';
}
</script>

<template>
    <Card class="h-full">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">Winrate by Format</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="!formatChart.length" class="flex flex-col items-center gap-2 py-8 text-center">
                <BarChart3 class="size-8 text-muted-foreground/40" />
                <p class="text-sm text-muted-foreground">No match data in this period</p>
            </div>

            <div v-else class="flex flex-col gap-3">
                <div v-for="stat in formatChart" :key="stat.format" class="flex items-center gap-3">
                    <span class="w-20 shrink-0 truncate text-sm font-medium">{{ stat.format }}</span>
                    <div class="h-5 flex-1 overflow-hidden rounded bg-muted">
                        <div
                            class="flex h-full items-center rounded transition-all"
                            :class="stat.winrate >= 50 ? 'bg-success/80' : 'bg-destructive/80'"
                            :style="{ width: `${Math.max(stat.winrate, 4)}%` }"
                        >
                            <span v-if="stat.winrate >= 20" class="px-2 text-xs font-semibold text-white tabular-nums">
                                {{ stat.winrate }}%
                            </span>
                        </div>
                    </div>
                    <span v-if="stat.winrate < 20" class="text-xs font-semibold tabular-nums" :class="winrateClass(stat.winrate)">
                        {{ stat.winrate }}%
                    </span>
                    <span class="w-16 shrink-0 text-right text-xs tabular-nums text-muted-foreground">
                        {{ stat.wins }}W – {{ stat.losses }}L
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
