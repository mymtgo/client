<script setup lang="ts">
import DeckCardGrid from '@/pages/decks/partials/DeckCardGrid.vue';
import { computed } from 'vue';

const props = defineProps<{
    archetype: App.Data.Front.ArchetypeData | null;
    stats: App.Data.Front.DeckGroupStatsData;
    decks: App.Data.Front.DeckData[];
    cardSize?: 'large' | 'compact';
}>();

const title = computed(() => props.archetype?.name ?? 'Unassigned');
const winrateDisplay = computed(() => (props.stats.record.total === 0 ? '—' : `${props.stats.record.winrate}%`));
const winrateColorClass = computed(() => {
    if (props.stats.record.total === 0) return 'text-muted-foreground';
    return props.stats.record.winrate >= 50 ? 'text-success' : 'text-destructive';
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <header class="flex items-baseline gap-3 border-b border-border/60 pb-1.5">
            <h3 class="text-sm font-semibold">{{ title }}</h3>
            <span class="text-xs tabular-nums">
                <span :class="winrateColorClass">{{ winrateDisplay }}</span>
                <span class="text-muted-foreground"> · {{ stats.record.total }} matches</span>
            </span>
        </header>

        <DeckCardGrid :decks="decks" :card-size="cardSize" />
    </section>
</template>
