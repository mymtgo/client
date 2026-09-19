<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import WinRateBar from '@/components/WinRateBar.vue';
import { TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    deckStats: App.Data.Front.DeckData[];
}>();

const sorted = computed(() => [...props.deckStats].filter((d) => d.record.total >= 3).sort((a, b) => b.record.winrate - a.record.winrate));

const bestDeck = computed(() => sorted.value[0] ?? null);
const worstDeck = computed(() => sorted.value[sorted.value.length - 1] ?? null);
</script>

<template>
    <Card v-if="bestDeck || worstDeck">
        <CardContent class="flex flex-col gap-4">
            <!-- Best performing deck -->
            <div v-if="bestDeck" class="flex flex-col gap-1.5">
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <TrendingUp class="size-3.5" />
                    <span class="tracking-wide uppercase">Best Performing</span>
                </div>
                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-base leading-tight font-semibold">{{ bestDeck.name }}</span>
                        <div class="flex items-center gap-2">
                            <Badge variant="outline">{{ bestDeck.format }}</Badge>
                            <span class="text-xs text-muted-foreground">{{ bestDeck.record.total }} matches</span>
                        </div>
                    </div>
                    <div class="w-28 shrink-0">
                        <WinRateBar :winrate="bestDeck.record.winrate" />
                    </div>
                </div>
            </div>

            <!-- Divider -->
            <div v-if="bestDeck && worstDeck && worstDeck.id !== bestDeck.id" class="border-t" />

            <!-- Worst performing deck -->
            <div v-if="worstDeck && worstDeck.id !== bestDeck?.id" class="flex flex-col gap-1.5">
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <TrendingDown class="size-3.5 text-destructive" />
                    <span class="tracking-wide uppercase">Worst Performing</span>
                </div>
                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-base leading-tight font-semibold">{{ worstDeck.name }}</span>
                        <div class="flex items-center gap-2">
                            <Badge variant="outline">{{ worstDeck.format }}</Badge>
                            <span class="text-xs text-muted-foreground">{{ worstDeck.record.total }} matches</span>
                        </div>
                    </div>
                    <div class="w-28 shrink-0">
                        <WinRateBar :winrate="worstDeck.record.winrate" />
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
