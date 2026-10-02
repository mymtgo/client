<script setup lang="ts">
import DeckListCard from '@/pages/decks/partials/DeckListCard.vue';

export type CardImageGroup = { key: string; label: string; cards: App.Data.Front.CardData[] };

/** Card images in labelled groups, the same stacked cards the deck decklist shows. */
defineProps<{ groups: CardImageGroup[]; emptyText?: string }>();

const count = (cards: App.Data.Front.CardData[]): number => cards.reduce((sum, card) => sum + card.quantity, 0);
</script>

<template>
    <div class="flex flex-wrap items-start gap-4">
        <section v-for="group in groups" :key="group.key" class="flex flex-col gap-2">
            <h4 class="text-xs font-medium text-muted-foreground">{{ group.label }} ({{ count(group.cards) }})</h4>
            <ul class="flex flex-wrap gap-2">
                <li v-for="card in group.cards" :key="`${group.key}_${card.mtgoId}`">
                    <DeckListCard :card="card" />
                </li>
            </ul>
        </section>
        <p v-if="groups.length === 0" class="text-sm text-muted-foreground">{{ emptyText ?? 'Empty.' }}</p>
    </div>
</template>
