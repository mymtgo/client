<script setup lang="ts">
import DeckCard from '@/pages/decks/partials/DeckCard.vue';
import DeckCardCompact from '@/pages/decks/partials/DeckCardCompact.vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        decks: App.Data.Front.DeckData[];
        cardSize?: 'large' | 'compact';
    }>(),
    { cardSize: 'large' },
);

const cardComponent = computed(() => (props.cardSize === 'compact' ? DeckCardCompact : DeckCard));
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <component :is="cardComponent" v-for="deck in decks" :key="deck.id" :deck="deck" />
    </div>
</template>
