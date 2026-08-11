<script setup lang="ts">
import ArchetypeNotes from '@/components/overlay/ArchetypeNotes.vue';
import { useCardHoverPreview } from '@/composables/useCardHoverPreview';
import { groupByType } from '@/composables/useCardTypeGroups';
import { computed } from 'vue';

/**
 * Sideboard guide pane, mirroring the draw odds panel's visual language:
 * cards grouped by type under uppercase headers, a leading count cell, the
 * card name, a right-aligned tabular figure, and a hover card-image preview
 * anchored to the window's right edge.
 *
 * The generated types describe `sidedIn`/`sidedOut` as Array<any> (a Spatie
 * serialization artifact), so give them a local shape.
 */
type SidedInCard = {
    oracleId: string;
    name: string;
    type: string | null;
    colorIdentity: string | null;
    image: string | null;
    quantity: number;
    sidedInGames: number;
    wins: number;
    losses: number;
    winrate: number | null;
};

type SidedOutCard = {
    oracleId: string;
    name: string;
    type: string | null;
    image: string | null;
    sidedOutGames: number;
};

const props = defineProps<{
    sideboard: App.Data.Front.SideboardGuideData | null;
    notes: { current: App.Data.Front.ArchetypeNoteData[]; other: App.Data.Front.ArchetypeNoteData[] };
    hasMatch: boolean;
    hasDeck: boolean;
    hasArchetype: boolean;
}>();

const emptyMessage = computed(() => {
    if (!props.hasMatch) return 'Waiting for match…';
    if (!props.hasDeck) return 'No deck linked to this match';
    if (!props.hasArchetype) return 'Pick an archetype to see your sideboard guide';
    return null;
});

const groupedSidedIn = computed<Record<string, SidedInCard[]>>(() =>
    groupByType((props.sideboard?.sidedIn ?? []) as SidedInCard[], (card) => card.type),
);

const sidedOutCards = computed<SidedOutCard[]>(() => (props.sideboard?.sidedOut ?? []) as SidedOutCard[]);

const groupQuantity = (cards: SidedInCard[]): number => cards.reduce((sum, card) => sum + card.quantity, 0);

const { hoveredCard, previewTop, onCardEnter, onCardLeave } = useCardHoverPreview<SidedInCard | SidedOutCard>();
</script>

<template>
    <div class="relative flex flex-col">
        <p v-if="emptyMessage" class="p-6 text-center text-xs text-muted-foreground">{{ emptyMessage }}</p>

        <template v-else-if="props.sideboard">
            <!-- Sample-size baseline, styled like the draw odds panel's header strip. -->
            <div class="px-4 py-2 text-[0.625rem] font-semibold tracking-wider text-muted-foreground/60 uppercase">
                <template v-if="props.sideboard.postboardGames > 0">
                    {{ props.sideboard.postboardGames }} post-board
                    {{ props.sideboard.postboardGames === 1 ? 'game' : 'games' }} · {{ props.sideboard.postboardRecord }} overall
                </template>
                <template v-else>No games vs this archetype yet</template>
            </div>

            <div v-for="(cards, type) in groupedSidedIn" :key="type">
                <h3
                    class="flex items-baseline justify-between gap-2 border-y py-2 pr-1.5 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
                >
                    <span>{{ type }} ({{ groupQuantity(cards) }})</span>
                </h3>
                <div class="divide-y text-xs">
                    <div
                        v-for="card in cards"
                        :key="card.oracleId"
                        class="flex items-center gap-2 text-sm"
                        :class="{ 'opacity-40': card.sidedInGames === 0 }"
                        @mouseenter="onCardEnter(card, $event)"
                        @mouseleave="onCardLeave"
                    >
                        <span class="w-8 min-w-0 shrink-0 border-r bg-black/20 px-2 py-1 text-center">
                            <span class="font-semibold tabular-nums">{{ card.quantity }}</span>
                        </span>
                        <span class="min-w-0 grow truncate">{{ card.name }}</span>
                        <div class="flex shrink-0 items-center gap-2 px-2">
                            <span class="w-20 text-right font-medium text-muted-foreground tabular-nums">
                                <template v-if="card.sidedInGames > 0">{{ card.wins }}–{{ card.losses }} ({{ card.winrate }}%)</template>
                                <template v-else>—</template>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <template v-if="sidedOutCards.length">
                <h3
                    class="flex items-baseline justify-between gap-2 border-y py-2 pr-1.5 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
                >
                    <span>Usually cut ({{ sidedOutCards.length }})</span>
                </h3>
                <div class="divide-y text-xs">
                    <div
                        v-for="card in sidedOutCards"
                        :key="card.oracleId"
                        class="flex items-center gap-2 text-sm"
                        @mouseenter="onCardEnter(card, $event)"
                        @mouseleave="onCardLeave"
                    >
                        <span class="w-8 min-w-0 shrink-0 border-r bg-black/20 px-2 py-1 text-center">
                            <span class="font-semibold tabular-nums">{{ card.sidedOutGames }}</span>
                        </span>
                        <span class="min-w-0 grow truncate">{{ card.name }}</span>
                        <div class="flex shrink-0 items-center gap-2 px-2">
                            <span class="w-20 text-right font-medium text-muted-foreground tabular-nums">{{ card.sidedOutGames }}× out</span>
                        </div>
                    </div>
                </div>
            </template>
        </template>

        <div class="px-3 pt-3 pb-3">
            <ArchetypeNotes
                :current="props.notes.current"
                :other="props.notes.other"
                :disabled="!props.hasDeck || !props.hasArchetype"
            />
        </div>

        <!-- Card image preview (inside window, anchored top-right) -->
        <Transition name="fade">
            <div v-if="hoveredCard?.image" class="pointer-events-none fixed right-2 z-50" :style="{ top: `${previewTop}px` }">
                <img :src="hoveredCard.image" :alt="hoveredCard.name" class="w-[200px] rounded-lg shadow-xl ring-1 ring-border" />
            </div>
        </Transition>
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
