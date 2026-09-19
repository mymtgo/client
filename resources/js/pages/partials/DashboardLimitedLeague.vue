<script setup lang="ts">
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { WidgetConfig } from '@/pages/partials/dashboardWidgets';
import { Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

type LimitedRun = {
    id: number;
    results: ('W' | 'L' | null)[];
    classification: string;
    startedAtHuman: string | null;
    gameWins: number;
    gameLosses: number;
    deck: { name: string } | null;
};

type LimitedLeague = {
    run: LimitedRun | null;
    setCode: string | null;
    setName: string | null;
    kind: 'draft' | 'sealed' | 'constructed';
};

const props = defineProps<{ data: LimitedLeague | null; config: WidgetConfig }>();

const wins = computed(() => props.data?.run?.results.filter((r) => r === 'W').length ?? 0);
const losses = computed(() => props.data?.run?.results.filter((r) => r === 'L').length ?? 0);
const kindLabel = computed(() => (props.data ? props.data.kind.charAt(0).toUpperCase() + props.data.kind.slice(1) : ''));
</script>

<template>
    <Card class="h-full">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium tracking-wide text-muted-foreground uppercase">Last limited league</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="!data || !data.run" class="py-6 text-center text-sm text-muted-foreground">No limited leagues yet</div>
            <div v-else class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <span class="truncate text-base leading-tight font-semibold">{{ data.setName ?? data.setCode ?? 'Unknown set' }}</span>
                    <Badge variant="outline">{{ kindLabel }}</Badge>
                </div>
                <div class="flex items-center gap-1.5">
                    <template v-for="(result, i) in data.run.results" :key="i">
                        <div v-if="result === null" class="h-2 w-2 rounded-full border border-muted-foreground/40" />
                        <ResultBadge v-else :won="result === 'W'" />
                    </template>
                    <Trophy v-if="data.run.classification === 'TROPHY'" class="size-3.5 text-yellow-400" />
                </div>
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span class="tabular-nums">{{ wins }}W - {{ losses }}L · games {{ data.run.gameWins }}-{{ data.run.gameLosses }}</span>
                    <span class="text-muted-foreground/60">{{ data.run.startedAtHuman }}</span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
