<script setup lang="ts">
import DeckCard from '@/pages/decks/partials/DeckCard.vue';
import { computed } from 'vue';

const props = defineProps<{
    archetype: App.Data.Front.ArchetypeData | null;
    stats: App.Data.Front.DeckGroupStatsData;
    decks: App.Data.Front.DeckData[];
}>();

const title = computed(() => props.archetype?.name ?? 'Unassigned');
const winrateDisplay = computed(() => (props.stats.winrate === null ? '—' : `${props.stats.winrate}%`));
const winrateColorClass = computed(() => {
    if (props.stats.winrate === null) return 'text-muted-foreground';
    return props.stats.winrate >= 50 ? 'text-success' : 'text-destructive';
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <header class="flex items-baseline gap-3 border-b border-border/60 pb-1.5">
            <h3 class="text-sm font-semibold">{{ title }}</h3>
            <span class="text-xs tabular-nums">
                <span :class="winrateColorClass">{{ winrateDisplay }}</span>
                <span class="text-muted-foreground"> · {{ stats.totalMatches }} matches</span>
            </span>
        </header>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <DeckCard v-for="deck in decks" :key="deck.id" :deck="deck" />
        </div>
    </section>
</template>
