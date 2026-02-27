<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import WinRateBar from '@/components/WinRateBar.vue';
import { TrendingUp, TrendingDown } from 'lucide-vue-next';

const props = defineProps<{
    deckStats: App.Data.Front.DeckData[];
}>();

const sorted = computed(() =>
    [...props.deckStats].filter((d) => d.matchesCount >= 3).sort((a, b) => b.winrate - a.winrate),
);

const bestDeck = computed(() => sorted.value[0] ?? null);
const worstDeck = computed(() => sorted.value[sorted.value.length - 1] ?? null);
</script>

<template>
    <div v-if="bestDeck || worstDeck" class="grid grid-cols-2 gap-4">
        <!-- Best performing deck -->
        <Card v-if="bestDeck">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium text-muted-foreground uppercase tracking-wide">
                    <TrendingUp class="size-4" />
                    Best Performing
                </CardTitle>
            </CardHeader>
            <CardContent class="flex items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <span class="text-lg font-semibold leading-tight">{{ bestDeck.name }}</span>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ bestDeck.format }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ bestDeck.matchesCount }} matches</span>
                    </div>
                </div>
                <div class="w-32 shrink-0">
                    <WinRateBar :winrate="bestDeck.winrate" />
                </div>
            </CardContent>
        </Card>

        <!-- Worst performing deck -->
        <Card v-if="worstDeck && worstDeck.id !== bestDeck?.id">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium text-muted-foreground uppercase tracking-wide">
                    <TrendingDown class="size-4 text-destructive" />
                    Worst Performing
                </CardTitle>
            </CardHeader>
            <CardContent class="flex items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <span class="text-lg font-semibold leading-tight">{{ worstDeck.name }}</span>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ worstDeck.format }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ worstDeck.matchesCount }} matches</span>
                    </div>
                </div>
                <div class="w-32 shrink-0">
                    <WinRateBar :winrate="worstDeck.winrate" />
                </div>
            </CardContent>
        </Card>
    </div>
</template>
