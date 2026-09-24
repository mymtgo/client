<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

type LeagueDistribution = {
    buckets: Record<string, number>;
    trophies: number;
    dropped: number;
    total: number;
    formatLabel: string | null;
};

const props = defineProps<{
    leagueDistribution: LeagueDistribution;
}>();

const title = computed(() => (props.leagueDistribution.formatLabel ? `${props.leagueDistribution.formatLabel} league results` : 'League results'));

const bucketOrder = ['5-0', '4-1', '3-2', '2-3', '1-4', '0-5'];

/** Finishes from 5-0 down, then runs the player dropped from. */
const bucketEntries = computed(() => [
    ...bucketOrder.map((key) => ({
        key,
        label: key,
        count: props.leagueDistribution.buckets[key] ?? 0,
        barClass: key === '5-0' ? 'bg-yellow-400' : 'bg-primary/60',
    })),
    { key: 'dropped', label: 'Drop', count: props.leagueDistribution.dropped ?? 0, barClass: 'bg-muted-foreground/60' },
]);

const maxCount = computed(() => Math.max(1, ...bucketEntries.value.map((b) => b.count)));
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium tracking-wide text-muted-foreground uppercase">{{ title }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <template v-if="leagueDistribution.total > 0">
                <div v-for="entry in bucketEntries" :key="entry.key" class="flex items-center gap-2 text-sm">
                    <span class="w-8 shrink-0 font-mono text-xs text-muted-foreground tabular-nums">{{ entry.label }}</span>
                    <div class="h-4 flex-1 overflow-hidden rounded-sm bg-muted">
                        <div
                            class="h-full rounded-sm transition-all"
                            :class="entry.barClass"
                            :style="{ width: `${(entry.count / maxCount) * 100}%` }"
                        />
                    </div>
                    <span class="w-5 shrink-0 text-right text-xs font-medium tabular-nums">{{ entry.count }}</span>
                </div>

                <div class="mt-2 flex items-center justify-between border-t pt-2 text-xs text-muted-foreground">
                    <div class="flex items-center gap-1">
                        <Trophy class="size-3 text-yellow-400" />
                        <span class="font-medium tabular-nums">{{ leagueDistribution.trophies }} trophies</span>
                    </div>
                    <span class="tabular-nums">{{ leagueDistribution.total }} total</span>
                </div>
            </template>

            <div v-else class="py-8 text-center text-sm text-muted-foreground">No completed leagues</div>
        </CardContent>
    </Card>
</template>
