<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type RollingForm = {
    results: string[];
    winrate: number;
    allTimeWinrate: number;
    delta: number;
};

const props = defineProps<{
    rollingForm: RollingForm;
}>();

const showDeltaBanner = computed(() => Math.abs(props.rollingForm.delta) >= 5);
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">
                Rolling Form · last {{ rollingForm.results.length }} matches
            </CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-3">
            <template v-if="rollingForm.results.length > 0">
                <!-- W/L pip squares -->
                <div class="flex flex-wrap gap-1">
                    <div
                        v-for="(result, i) in rollingForm.results"
                        :key="i"
                        class="flex h-6 w-6 items-center justify-center rounded-sm text-xs font-bold text-white"
                        :class="{
                            'bg-success': result === 'W',
                            'bg-destructive': result === 'L',
                            'bg-muted text-muted-foreground': result === 'D',
                        }"
                    >
                        {{ result }}
                    </div>
                </div>

                <!-- Winrate comparison -->
                <div class="flex items-center justify-between text-sm">
                    <div class="flex flex-col items-center gap-0.5">
                        <span class="text-xl font-bold tabular-nums" :class="rollingForm.winrate >= 50 ? 'text-success' : 'text-destructive'">
                            {{ rollingForm.winrate }}%
                        </span>
                        <span class="text-xs text-muted-foreground">Rolling</span>
                    </div>
                    <span class="text-muted-foreground/40">vs</span>
                    <div class="flex flex-col items-center gap-0.5">
                        <span class="text-xl font-bold tabular-nums">{{ rollingForm.allTimeWinrate }}%</span>
                        <span class="text-xs text-muted-foreground">All-time</span>
                    </div>
                </div>

                <!-- Delta banner -->
                <div
                    v-if="showDeltaBanner"
                    class="rounded-md px-3 py-1.5 text-center text-xs font-medium"
                    :class="rollingForm.delta > 0 ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive'"
                >
                    {{ rollingForm.delta > 0 ? '+' : '' }}{{ rollingForm.delta }}% vs all-time average
                </div>
            </template>

            <div v-else class="py-8 text-center text-sm text-muted-foreground">
                No matches yet
            </div>
        </CardContent>
    </Card>
</template>
