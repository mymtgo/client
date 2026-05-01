<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import type { LeagueKpis } from '@/types/leagues';
import { Activity, Coins, Swords, Target, Trophy, Users } from 'lucide-vue-next';

defineProps<{ kpis: LeagueKpis }>();
</script>

<template>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Activity class="size-3" /> Runs
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.runs.total }}</span>
                <span class="text-sm text-muted-foreground">
                    {{ kpis.runs.completed }} completed · {{ kpis.runs.live }} live
                </span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Trophy class="size-3" /> Trophies
                </span>
                <span class="text-3xl font-bold tabular-nums">{{ kpis.trophies }}</span>
                <span class="text-sm text-muted-foreground">5-0 finishes</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Target class="size-3" /> Trophy Rate
                </span>
                <span class="text-3xl font-bold tabular-nums">
                    {{ kpis.trophyRate !== null ? kpis.trophyRate + '%' : '—' }}
                </span>
                <span class="text-sm text-muted-foreground">across {{ kpis.runs.completed }} runs</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Coins class="size-3" /> Cash Rate
                </span>
                <span class="text-3xl font-bold tabular-nums">
                    {{ kpis.cashRate !== null ? kpis.cashRate + '%' : '—' }}
                </span>
                <span class="text-sm text-muted-foreground">4-1 or better</span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Users class="size-3" /> Avg Finish
                </span>
                <span class="text-3xl font-bold tabular-nums">
                    {{ kpis.avgFinish !== null ? kpis.avgFinish : '—' }}
                </span>
                <span class="text-sm text-muted-foreground">
                    {{ kpis.avgFinish !== null ? 'wins per run' : 'no completed runs' }}
                </span>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0">
            <CardContent class="flex flex-col gap-0.5 p-3">
                <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase">
                    <Swords class="size-3" /> Top Matchup
                </span>
                <template v-if="kpis.topMatchup">
                    <span class="truncate text-base font-semibold leading-9">{{ kpis.topMatchup.archetype }}</span>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        {{ kpis.topMatchup.wins }}-{{ kpis.topMatchup.losses }} · {{ kpis.topMatchup.count }} played
                    </span>
                </template>
                <template v-else>
                    <span class="text-base font-semibold leading-9 text-muted-foreground">—</span>
                    <span class="text-sm text-muted-foreground">Not enough data</span>
                </template>
            </CardContent>
        </Card>
    </div>
</template>
