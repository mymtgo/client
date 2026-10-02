<script setup lang="ts">
import { Separator } from '@/components/ui/separator';
import { normalizeType, TYPE_ORDER } from '@/lib/cardTypes';
import DeckListCard from '@/pages/decks/partials/DeckListCard.vue';
import { computed } from 'vue';

const props = defineProps<{
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
}>();

const getCount = (cards: App.Data.Front.CardData[]) => cards.reduce((sum, c) => sum + c.quantity, 0);

const groupedMaindeck = computed(() => {
    const merged: Record<string, App.Data.Front.CardData[]> = {};
    for (const [rawType, cards] of Object.entries(props.maindeck)) {
        const key = normalizeType(rawType);
        (merged[key] ??= []).push(...cards);
    }
    return Object.fromEntries(Object.entries(merged).sort(([a], [b]) => (TYPE_ORDER[a] ?? 99) - (TYPE_ORDER[b] ?? 99)));
});
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-2">
            <h3 class="text-sm font-semibold tracking-tight">Maindeck</h3>

            <div class="flex flex-wrap items-start gap-4">
                <section v-for="(cards, type) in groupedMaindeck" :key="`group_${type}`" class="flex flex-col gap-2">
                    <h4 class="text-xs font-medium text-muted-foreground">{{ type }} ({{ getCount(cards) }})</h4>
                    <ul class="flex flex-wrap gap-2">
                        <li v-for="card in cards" :key="`card_${card.mtgoId ?? card.name}`">
                            <DeckListCard :card="card" />
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <Separator />

        <div class="space-y-2">
            <h4 class="text-xs font-medium text-muted-foreground">Sideboard ({{ getCount(sideboard) }})</h4>
            <ul class="flex flex-wrap gap-2">
                <li v-for="card in sideboard" :key="`card_${card.mtgoId ?? card.name}`">
                    <DeckListCard :card="card" />
                </li>
            </ul>
        </div>
    </div>
</template>
