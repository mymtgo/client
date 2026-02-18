<script setup lang="ts">
import { Separator } from '@/components/ui/separator';
import { sumBy } from 'lodash';
import DeckListCard from '@/pages/decks/partials/DeckListCard.vue';

defineProps<{
    maindeck: App.Data.Front.CardData[];
    sideboard: App.Data.Front.CardData[];
}>();

const getCount = (cards: App.Data.Front.CardData[]) => sumBy(cards, 'quantity');
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-2">
            <h3 class="text-sm font-semibold tracking-tight">Maindeck</h3>

            <div class="space-y-2">
                <section v-for="(cards, type) in maindeck" :key="`group_${type}`" class="space-y-1">
                    <h4 class="text-xs font-medium text-muted-foreground">{{ type }} ({{ getCount(cards) }})</h4>
                    <ul class="space-y-1">
                        <li v-for="card in cards" :key="`card_${card.id}`">
                            <DeckListCard :card="card" />
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <Separator />

        <div class="space-y-2">
            <h3 class="text-sm font-semibold tracking-tight">Sideboard</h3>
            <ul class="space-y-1">
                <li v-for="card in sideboard" :key="`card_${card.id}`">
                    <DeckListCard :card="card" />
                </li>
            </ul>
        </div>
    </div>
</template>
