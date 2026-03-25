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
    <Card v-if="bestDeck || worstDeck">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">Deck Performance</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <!-- Best performing deck -->
            <div v-if="bestDeck" class="flex flex-col gap-1.5">
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <TrendingUp class="size-3.5" />
                    <span class="uppercase tracking-wide">Best Performing</span>
                </div>
                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-base font-semibold leading-tight">{{ bestDeck.name }}</span>
                        <div class="flex items-center gap-2">
                            <Badge variant="outline">{{ bestDeck.format }}</Badge>
                            <span class="text-xs text-muted-foreground">{{ bestDeck.matchesCount }} matches</span>
                        </div>
                    </div>
                    <div class="w-28 shrink-0">
                        <WinRateBar :winrate="bestDeck.winrate" />
                    </div>
                </div>
            </div>

            <!-- Divider -->
            <div v-if="bestDeck && worstDeck && worstDeck.id !== bestDeck.id" class="border-t" />

            <!-- Worst performing deck -->
            <div v-if="worstDeck && worstDeck.id !== bestDeck?.id" class="flex flex-col gap-1.5">
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <TrendingDown class="size-3.5 text-destructive" />
                    <span class="uppercase tracking-wide">Worst Performing</span>
                </div>
                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-base font-semibold leading-tight">{{ worstDeck.name }}</span>
                        <div class="flex items-center gap-2">
                            <Badge variant="outline">{{ worstDeck.format }}</Badge>
                            <span class="text-xs text-muted-foreground">{{ worstDeck.matchesCount }} matches</span>
                        </div>
                    </div>
                    <div class="w-28 shrink-0">
                        <WinRateBar :winrate="worstDeck.winrate" />
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
