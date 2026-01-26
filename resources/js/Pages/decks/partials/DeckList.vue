<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { sum, sumBy } from 'lodash';
import DeckListCard from '@/Pages/decks/partials/DeckListCard.vue';

defineProps<{
    maindeck: App.Data.Front.CardData[];
    sideboard: App.Data.Front.CardData[];
}>();

const getCount = (cards: App.Data.Front.CardData[]) => sumBy(cards, 'quantity');
</script>

<template>
    <div>
        <header>Maindeck</header>

        <div class="mt-2 space-y-2">
            <section v-for="(cards, type) in maindeck" :key="`group_${type}`" class="space-y-2">
                <header class="text-sm font-semibold text-sidebar-foreground/70">{{ type }} ({{ getCount(cards) }})</header>
                <ul class="space-y-1">
                    <li v-for="card in cards" :key="`card_${card.id}`">
                        <DeckListCard :card="card" />
                    </li>
                </ul>
            </section>
        </div>
        <div>
            <header class="my-2">Sideboard</header>
            <ul class="space-y-1">
                <li v-for="card in sideboard" :key="`card_${card.id}`">
                    <DeckListCard :card="card" />
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped></style>
