<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import type { LeagueKpis } from '@/types/leagues';
import { Activity, Coins, Swords, Target, Trophy, Users } from 'lucide-vue-next';

defineProps<{ kpis: LeagueKpis }>();
</script>

<template>
    <Card>
        <CardContent
            class="grid grid-cols-2 divide-x divide-y divide-border p-0 md:grid-cols-3 lg:grid-cols-6"
        >
            <div class="flex flex-col gap-1 p-4">
                <div class="inline-flex items-center gap-1 text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Activity class="size-3" /> Runs
                </div>
                <div class="text-2xl font-bold tabular-nums">{{ kpis.runs.total }}</div>
                <div class="text-xs text-muted-foreground">
                    {{ kpis.runs.completed }} completed · {{ kpis.runs.live }} live
                </div>
            </div>

            <div class="flex flex-col gap-1 p-4">
                <div class="inline-flex items-center gap-1 text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Trophy class="size-3" /> Trophies
                </div>
                <div class="text-2xl font-bold tabular-nums">{{ kpis.trophies }}</div>
                <div class="text-xs text-muted-foreground">5-0 finishes</div>
            </div>

            <div class="flex flex-col gap-1 p-4">
                <div class="inline-flex items-center gap-1 text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Target class="size-3" /> Trophy rate
                </div>
                <div class="text-2xl font-bold tabular-nums">
                    {{ kpis.trophyRate !== null ? kpis.trophyRate + '%' : '—' }}
                </div>
                <div class="text-xs text-muted-foreground">across {{ kpis.runs.completed }} runs</div>
            </div>

            <div class="flex flex-col gap-1 p-4">
                <div class="inline-flex items-center gap-1 text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Coins class="size-3" /> Cash rate
                </div>
                <div class="text-2xl font-bold tabular-nums">
                    {{ kpis.cashRate !== null ? kpis.cashRate + '%' : '—' }}
                </div>
                <div class="text-xs text-muted-foreground">4-1 or better</div>
            </div>

            <div class="flex flex-col gap-1 p-4">
                <div class="inline-flex items-center gap-1 text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Users class="size-3" /> Avg finish
                </div>
                <div class="text-2xl font-bold tabular-nums">
                    {{ kpis.avgFinish !== null ? kpis.avgFinish + ' wins' : '—' }}
                </div>
                <div class="text-xs text-muted-foreground">across {{ kpis.runs.completed }} runs</div>
            </div>

            <div class="flex flex-col gap-1 p-4">
                <div class="inline-flex items-center gap-1 text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Swords class="size-3" /> Top matchup
                </div>
                <div v-if="kpis.topMatchup" class="truncate text-base font-semibold">
                    {{ kpis.topMatchup.archetype }}
                </div>
                <div v-else class="text-base font-semibold text-muted-foreground">—</div>
                <div v-if="kpis.topMatchup" class="text-xs text-muted-foreground">
                    {{ kpis.topMatchup.wins }}-{{ kpis.topMatchup.losses }} · {{ kpis.topMatchup.count }} played
                </div>
            </div>
        </CardContent>
    </Card>
</template>
