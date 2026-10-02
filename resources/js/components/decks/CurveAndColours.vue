<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { computed } from 'vue';

/**
 * Mana curve and colour split of a card list, lands left out. `scope` names
 * the list in both headings, e.g. "maindeck, nonland".
 */
const props = defineProps<{ cards: App.Data.Front.CardData[]; scope: string }>();

type ColorStat = { color: string; label: string; count: number; total: number; percentage: number };
type CmcBucket = { cmc: string; count: number };

const COLORS = [
    { color: 'W', label: 'White' },
    { color: 'U', label: 'Blue' },
    { color: 'B', label: 'Black' },
    { color: 'R', label: 'Red' },
    { color: 'G', label: 'Green' },
    { color: 'C', label: 'Colorless' },
];

const nonLand = computed(() => props.cards.filter((card) => !card.type?.includes('Land')));

const colorDistribution = computed((): ColorStat[] => {
    const total = nonLand.value.reduce((sum, c) => sum + c.quantity, 0);
    if (total === 0) return [];

    return COLORS.map(({ color, label }) => {
        const count = nonLand.value
            .filter((c) => {
                if (color === 'C') return !c.identity || c.identity === '' || c.identity === 'C';
                return c.identity?.split(',').includes(color);
            })
            .reduce((sum, c) => sum + c.quantity, 0);

        return { color, label, count, total, percentage: Math.round((count / total) * 100) };
    }).filter((stat) => stat.count > 0);
});

const cmcDistribution = computed((): CmcBucket[] => {
    const buckets = new Map<number, number>();
    for (const card of nonLand.value) {
        const cmc = Math.floor(card.cmc ?? 0);
        buckets.set(cmc, (buckets.get(cmc) ?? 0) + card.quantity);
    }

    // Cap at a 7+ bucket
    const result = new Map<string, number>();
    for (const [cmc, count] of [...buckets.entries()].sort((a, b) => a[0] - b[0])) {
        const key = cmc >= 7 ? '7+' : String(cmc);
        result.set(key, (result.get(key) ?? 0) + count);
    }

    return [...result.entries()].map(([cmc, count]) => ({ cmc, count }));
});

const cmcMax = computed(() => Math.max(...cmcDistribution.value.map((d) => d.count), 1));
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-if="cmcDistribution.length" class="flex flex-col gap-2">
            <h3 class="text-sm font-medium text-muted-foreground">
                Mana Curve <span class="text-xs font-normal">({{ scope }})</span>
            </h3>
            <div class="flex items-end gap-1" style="height: 120px">
                <div v-for="bucket in cmcDistribution" :key="bucket.cmc" class="flex flex-1 flex-col items-center gap-1">
                    <span class="text-xs text-muted-foreground tabular-nums">{{ bucket.count }}</span>
                    <div class="w-full rounded-t bg-primary/80 transition-all" :style="{ height: `${(bucket.count / cmcMax) * 90}px` }" />
                    <span class="text-xs text-muted-foreground tabular-nums">{{ bucket.cmc }}</span>
                </div>
            </div>
        </div>

        <div v-if="colorDistribution.length" class="flex flex-col gap-2">
            <h3 class="text-sm font-medium text-muted-foreground">
                Color Distribution <span class="text-xs font-normal">({{ scope }})</span>
            </h3>
            <div class="grid grid-cols-2 gap-2">
                <div
                    v-for="stat in colorDistribution"
                    :key="stat.color"
                    class="flex items-center gap-3 rounded-md border border-border bg-muted/30 px-3 py-2.5"
                >
                    <ManaSymbols :symbols="stat.color" class="shrink-0" />
                    <div class="flex flex-1 flex-col">
                        <span class="text-sm font-semibold tabular-nums">{{ stat.percentage }}%</span>
                        <span class="text-xs text-muted-foreground">{{ stat.count }} of {{ stat.total }} cards</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
