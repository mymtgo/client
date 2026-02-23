<script setup lang="ts">
import { computed } from 'vue';
import { Separator } from '@/components/ui/separator';
import { sumBy } from 'lodash';
import DeckListCard from '@/pages/decks/partials/DeckListCard.vue';

const props = defineProps<{
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
}>();

const getCount = (cards: App.Data.Front.CardData[]) => sumBy(cards, 'quantity');

// Canonical MTG permanent/spell types in display order.
// The first match wins, so Creature beats Artifact for "Artifact Creature".
const CANONICAL_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'] as const;
const TYPE_ORDER = Object.fromEntries(CANONICAL_TYPES.map((t, i) => [t, i]));

function normalizeType(raw: string): string {
    for (const canonical of CANONICAL_TYPES) {
        if (raw.includes(canonical)) return canonical;
    }
    return raw;
}

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
