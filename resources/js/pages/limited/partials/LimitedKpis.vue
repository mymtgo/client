<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { NO_VALUE, formatSeconds } from '@/types/limited';
import { Activity, Clock, Layers, Swords, Target, Timer } from 'lucide-vue-next';

defineProps<{ kpis: App.Data.Front.LimitedIndexKpisData }>();
</script>

<template>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Activity class="size-3" /> Events
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.events }}</span>
                <span class="text-sm text-muted-foreground">{{ kpis.drafts }} drafts · {{ kpis.unlinked }} unlinked</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Swords class="size-3" /> Match win
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.matchWinPct !== null ? kpis.matchWinPct + '%' : NO_VALUE }}</span>
                <span class="text-sm text-muted-foreground">
                    {{ kpis.matchWins }}-{{ kpis.matchLosses }} across {{ kpis.matchWins + kpis.matchLosses }} matches
                </span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Target class="size-3" /> Avg record
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.avgWins !== null ? `${kpis.avgWins}-${kpis.avgLosses}` : NO_VALUE }}</span>
                <span class="text-sm text-muted-foreground">per completed run</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Layers class="size-3" /> Most drafted
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.mostDraftedSet ?? NO_VALUE }}</span>
                <span class="text-sm text-muted-foreground">{{ kpis.mostDraftedCount }} runs</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Timer class="size-3" /> Avg pick time
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ formatSeconds(kpis.avgPickSeconds) }}</span>
                <span class="text-sm text-muted-foreground">shown to committed</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Clock class="size-3" /> Indecision
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.indecisionPct !== null ? kpis.indecisionPct + '%' : NO_VALUE }}</span>
                <span class="text-sm text-muted-foreground">picks with 2+ reservations</span>
            </CardContent>
        </Card>
    </div>
</template>
