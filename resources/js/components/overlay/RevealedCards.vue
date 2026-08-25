<script setup lang="ts">
import OverlayCardRow from '@/components/overlay/OverlayCardRow.vue';
import { useCardHoverPreview } from '@/composables/useCardHoverPreview';
import { groupByType } from '@/composables/useCardTypeGroups';
import { computed } from 'vue';

/**
 * Every card the opponent has revealed this match, grouped by type — the same
 * visual language as the draw odds panel (leading count, name, hover image
 * preview anchored inside the frameless window).
 */
type RevealedCard = App.Data.Front.RevealedCardData;

const props = defineProps<{
    reveals: RevealedCard[] | null;
    hasMatch: boolean;
}>();

const { hoveredCard, previewTop, onCardEnter, onCardLeave } = useCardHoverPreview<RevealedCard>();

const groupedCards = computed<Record<string, RevealedCard[]>>(() => groupByType(props.reveals ?? [], (card) => card.type));

const totalRevealed = computed(() => (props.reveals ?? []).reduce((sum, card) => sum + card.quantity, 0));

const isEmpty = computed(() => !props.hasMatch || (props.reveals ?? []).length === 0);

const groupCount = (cards: RevealedCard[]): number => cards.reduce((sum, card) => sum + card.quantity, 0);
</script>

<template>
    <div class="relative flex h-full flex-col bg-background text-foreground">
        <div v-if="isEmpty" class="flex h-full items-center justify-center p-6" style="-webkit-app-region: drag">
            <p class="text-sm text-muted-foreground">
                {{ props.hasMatch ? 'Nothing revealed yet' : 'Waiting for match…' }}
            </p>
        </div>

        <div v-else class="flex h-full min-h-0 flex-col">
            <div class="flex shrink-0 items-center justify-between gap-2 bg-background px-4 py-2" style="-webkit-app-region: drag">
                <span class="text-[0.625rem] font-semibold tracking-wider text-muted-foreground/60 uppercase">Revealed this match</span>
                <span class="text-sm font-semibold tabular-nums">{{ totalRevealed }}</span>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto pb-4">
                <div v-for="(cards, type) in groupedCards" :key="type">
                    <h3 class="border-y py-2 pr-1.5 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase">
                        {{ type }} ({{ groupCount(cards) }})
                    </h3>
                    <div class="divide-y text-xs">
                        <OverlayCardRow
                            v-for="card in cards"
                            :key="card.mtgoId ?? card.name"
                            :name="card.name"
                            :count="card.quantity"
                            :art-crop="card.artCrop"
                            @mouseenter="onCardEnter(card, $event)"
                            @mouseleave="onCardLeave"
                        />
                    </div>
                </div>
            </div>

            <!-- Card image preview (inside window, anchored top-right) -->
            <Transition name="fade">
                <div v-if="hoveredCard?.image" class="pointer-events-none fixed right-2 z-50" :style="{ top: `${previewTop}px` }">
                    <img :src="hoveredCard.image" :alt="hoveredCard.name" class="w-[200px] rounded-lg shadow-xl ring-1 ring-border" />
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.fade-enter-active {
    transition: opacity 0.1s ease;
}
.fade-leave-active {
    transition: opacity 0.05s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
