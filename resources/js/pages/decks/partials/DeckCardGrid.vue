<script setup lang="ts">
import DeckCard from '@/pages/decks/partials/DeckCard.vue';
import DeckCardCompact from '@/pages/decks/partials/DeckCardCompact.vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        decks: App.Data.Front.DeckData[];
        cardSize?: 'large' | 'compact';
        selectedIds?: number[];
        /** Display format shared by the current selection; decks of another format cannot join it. */
        selectionFormat?: string | null;
    }>(),
    { cardSize: 'large', selectedIds: () => [], selectionFormat: null },
);

const emit = defineEmits<{
    'update:selected': [deckId: number, value: boolean];
    dragstart: [format: string];
    dragend: [];
}>();

const cardComponent = computed(() => (props.cardSize === 'compact' ? DeckCardCompact : DeckCard));
const selectionActive = computed(() => props.selectedIds.length > 0);
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <component
            :is="cardComponent"
            v-for="deck in decks"
            :key="deck.id"
            :deck="deck"
            :selected="selectedIds.includes(deck.id)"
            :selection-active="selectionActive"
            :selected-ids="selectedIds"
            :selectable="selectionFormat === null || deck.format === selectionFormat"
            @update:selected="(id: number, value: boolean) => emit('update:selected', id, value)"
            @dragstart="(format: string) => emit('dragstart', format)"
            @dragend="emit('dragend')"
        />
    </div>
</template>
