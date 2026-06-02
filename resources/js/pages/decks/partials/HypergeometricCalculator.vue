<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { hypergeometric } from '@/composables/useHypergeometric';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    maindeck: Record<string, App.Data.Front.CardData[]>;
}>();

type DeckCard = { name: string; quantity: number };

const cards = computed((): DeckCard[] =>
    Object.values(props.maindeck)
        .flat()
        .filter((card): card is App.Data.Front.CardData & { name: string } => Boolean(card.name))
        .map((card) => ({ name: card.name, quantity: card.quantity })),
);

const deckTotal = computed((): number => cards.value.reduce((sum, card) => sum + card.quantity, 0));

const selectedName = ref<string>('');

const selectedCard = computed((): DeckCard | null => cards.value.find((card) => card.name === selectedName.value) ?? null);

// Editable inputs. N prefills to deck total; K refills on card change.
const populationSize = ref<number>(deckTotal.value);
const successesInPopulation = ref<number>(0);
const sampleSize = ref<number>(7);
const successesInSample = ref<number>(1);

// Keep N in sync with the deck total until the user starts editing it. Once a
// card is chosen, N is left to the user; before that it tracks the deck total.
watch(deckTotal, (total) => {
    if (!selectedCard.value) {
        populationSize.value = total;
    }
});

// Card change refills K with the card's quantity. Manual K edits then stand
// until the card changes again.
watch(selectedCard, (card) => {
    if (card) {
        successesInPopulation.value = card.quantity;
        if (!populationSize.value) {
            populationSize.value = deckTotal.value;
        }
    }
});

const toNumber = (value: number | string): number => {
    const parsed = typeof value === 'number' ? value : parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
};

const results = computed(() =>
    hypergeometric(
        toNumber(populationSize.value),
        toNumber(successesInPopulation.value),
        toNumber(sampleSize.value),
        toNumber(successesInSample.value),
    ),
);

const formatPercent = (value: number): string => `${(value * 100).toFixed(1)}%`;

const sampleK = computed((): number => Math.max(0, toNumber(successesInSample.value)));

const resultLines = computed(() => [
    { label: `Chance to draw ${sampleK.value} or more`, value: formatPercent(results.value.atLeast) },
    { label: `Chance to draw exactly ${sampleK.value}`, value: formatPercent(results.value.exactly) },
    { label: `Chance to draw ${sampleK.value} or less`, value: formatPercent(results.value.atMost) },
    { label: 'Chance to draw 0', value: formatPercent(results.value.zero) },
]);
</script>

<template>
    <div v-if="cards.length" class="flex flex-col gap-2">
        <h3 class="text-sm font-medium text-muted-foreground">Draw Odds <span class="text-xs font-normal">(hypergeometric)</span></h3>

        <div class="flex flex-col gap-3 rounded-md border border-border bg-muted/30 px-3 py-3">
            <div class="flex flex-col gap-1.5">
                <Label for="hyp-card" class="text-xs text-muted-foreground">Card</Label>
                <NativeSelect id="hyp-card" v-model="selectedName" class="h-8 w-full text-sm">
                    <NativeSelectOption value="" disabled>Select a card…</NativeSelectOption>
                    <NativeSelectOption v-for="card in cards" :key="card.name" :value="card.name">
                        {{ card.name }}
                    </NativeSelectOption>
                </NativeSelect>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="hyp-n" class="text-xs text-muted-foreground">Population Size</Label>
                    <Input id="hyp-n" v-model.number="populationSize" type="number" min="0" :disabled="!selectedCard" class="h-8 tabular-nums" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="hyp-k-pop" class="text-xs text-muted-foreground">Successes in Pop.</Label>
                    <Input
                        id="hyp-k-pop"
                        v-model.number="successesInPopulation"
                        type="number"
                        min="0"
                        :disabled="!selectedCard"
                        class="h-8 tabular-nums"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="hyp-sample" class="text-xs text-muted-foreground">Sample Size</Label>
                    <Input id="hyp-sample" v-model.number="sampleSize" type="number" min="0" :disabled="!selectedCard" class="h-8 tabular-nums" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="hyp-k" class="text-xs text-muted-foreground">Successes in Sample</Label>
                    <Input id="hyp-k" v-model.number="successesInSample" type="number" min="0" :disabled="!selectedCard" class="h-8 tabular-nums" />
                </div>
            </div>

            <div
                class="flex flex-col gap-1.5 border-t border-border pt-3 transition-opacity"
                :class="selectedCard ? 'opacity-100' : 'pointer-events-none opacity-40'"
            >
                <div v-for="line in resultLines" :key="line.label" class="flex items-baseline justify-between gap-2">
                    <span class="text-xs text-muted-foreground">{{ line.label }}</span>
                    <span class="text-sm font-semibold tabular-nums">{{ selectedCard ? line.value : '—' }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
