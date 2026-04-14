<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Trophy } from 'lucide-vue-next';

type LeagueDistribution = {
    buckets: Record<string, number>;
    trophies: number;
    total: number;
};

const props = defineProps<{
    leagueDistribution: LeagueDistribution;
}>();

const bucketOrder = ['5-0', '4-1', '3-2', '2-3', '1-4', '0-5'];

const bucketEntries = computed(() =>
    bucketOrder.map((key) => ({
        key,
        count: props.leagueDistribution.buckets[key] ?? 0,
    })),
);

const maxCount = computed(() => Math.max(1, ...bucketEntries.value.map((b) => b.count)));
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">League Results</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <template v-if="leagueDistribution.total > 0">
                <div
                    v-for="entry in bucketEntries"
                    :key="entry.key"
                    class="flex items-center gap-2 text-sm"
                >
                    <span class="w-8 shrink-0 font-mono text-xs text-muted-foreground tabular-nums">{{ entry.key }}</span>
                    <div class="h-4 flex-1 overflow-hidden rounded-sm bg-muted">
                        <div
                            class="h-full rounded-sm transition-all"
                            :class="entry.key === '5-0' ? 'bg-yellow-400' : 'bg-primary/60'"
                            :style="{ width: `${(entry.count / maxCount) * 100}%` }"
                        />
                    </div>
                    <span class="w-5 shrink-0 text-right tabular-nums text-xs font-medium">{{ entry.count }}</span>
                </div>

                <div class="mt-2 flex items-center justify-between border-t pt-2 text-xs text-muted-foreground">
                    <div class="flex items-center gap-1">
                        <Trophy class="size-3 text-yellow-400" />
                        <span class="tabular-nums font-medium">{{ leagueDistribution.trophies }} trophies</span>
                    </div>
                    <span class="tabular-nums">{{ leagueDistribution.total }} total</span>
                </div>
            </template>

            <div v-else class="py-8 text-center text-sm text-muted-foreground">
                No completed leagues
            </div>
        </CardContent>
    </Card>
</template>
