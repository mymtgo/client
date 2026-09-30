<script setup lang="ts">
import OverlayCardTypeGroups from '@/components/overlay/OverlayCardTypeGroups.vue';
import { useCardHoverPreview } from '@/composables/useCardHoverPreview';
import { computed } from 'vue';

/**
 * Every card the opponent has revealed this match, grouped by type — the same
 * visual language as the draw odds panel (leading count, name, hover image
 * preview anchored inside the frameless window). Once their archetype is
 * known, the cards its lists play that have not shown up yet follow, with
 * the copies still unaccounted for, as a prompt of what else might be coming.
 */
type RevealedCard = App.Data.Front.RevealedCardData;
type PotentialCard = App.Data.Front.PotentialCardData;

const props = defineProps<{
    reveals: RevealedCard[] | null;
    potential: { maindeck: PotentialCard[]; sideboard: PotentialCard[] } | null;
    hasMatch: boolean;
}>();

const { hoveredCard, previewTop, onCardEnter, onCardLeave } = useCardHoverPreview<RevealedCard | PotentialCard>();

const potentialGroups = computed(() =>
    [
        { key: 'maindeck', label: 'Could still have', cards: props.potential?.maindeck ?? [] },
        { key: 'sideboard', label: 'Potential sideboard cards', cards: props.potential?.sideboard ?? [] },
    ].filter((group) => group.cards.length > 0),
);

const totalRevealed = computed(() => (props.reveals ?? []).reduce((sum, card) => sum + card.quantity, 0));

const hasReveals = computed(() => (props.reveals ?? []).length > 0);

const isEmpty = computed(() => !props.hasMatch || (!hasReveals.value && potentialGroups.value.length === 0));
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
                <p v-if="!hasReveals" class="border-t px-4 py-2 text-xs text-muted-foreground">Nothing revealed yet</p>

                <OverlayCardTypeGroups :cards="props.reveals ?? []" @enter="onCardEnter" @leave="onCardLeave" />

                <!-- Not yet seen: what the archetype's lists usually play, less what has shown up. -->
                <div v-for="group in potentialGroups" :key="group.key" class="mt-3">
                    <div class="flex items-center justify-between gap-2 px-4 py-2">
                        <span class="text-[0.625rem] font-semibold tracking-wider text-muted-foreground/60 uppercase">{{ group.label }}</span>
                    </div>
                    <OverlayCardTypeGroups :cards="group.cards" class="text-muted-foreground" @enter="onCardEnter" @leave="onCardLeave" />
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
