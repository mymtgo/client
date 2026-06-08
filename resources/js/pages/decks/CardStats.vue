<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckCardStats from '@/pages/decks/partials/DeckCardStats.vue';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    cardStats?: any;
}>();
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <DeckCardStats
            :card-stats="cardStats"
            :deck-id="deck.id"
            :deck-archetype-id="deck.archetype?.id ?? null"
            :timeframe="timeframe"
            :deleted-at="deck.deletedAt"
        />
    </div>
</template>
