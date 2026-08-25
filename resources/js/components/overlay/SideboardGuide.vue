<script setup lang="ts">
import ArchetypeNotes from '@/components/overlay/ArchetypeNotes.vue';
import OverlayCardRow from '@/components/overlay/OverlayCardRow.vue';
import { useCardHoverPreview } from '@/composables/useCardHoverPreview';
import { groupByType } from '@/composables/useCardTypeGroups';
import { useOfflineMode } from '@/composables/useOfflineMode';
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
    communitySidedIn: number | null;
    communityGames: number | null;
    communityRate: number | null;
};

type SidedOutCard = {
    oracleId: string;
    name: string;
    type: string | null;
    image: string | null;
    sidedOutGames: number;
    communitySidedOut: number | null;
    communityGames: number | null;
    communityRate: number | null;
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

const sidedInCards = computed<SidedInCard[]>(() => (props.sideboard?.sidedIn ?? []) as SidedInCard[]);

/**
 * Type groups, ordered by the best community inclusion rate inside each.
 *
 * The panel groups by card type to mirror the draw odds pane, but the question
 * it answers is "what does the field bring in", so the group holding the 90%
 * card has to come before the group holding the 20% one. With no community
 * data at all every group scores -1 and groupByType's own order stands.
 */
const groupedSidedIn = computed<Record<string, SidedInCard[]>>(() => {
    const groups = groupByType(sidedInCards.value, (card) => card.type);

    const best = (cards: SidedInCard[]): number => Math.max(-1, ...cards.map((card) => card.communityRate ?? -1));

    return Object.fromEntries(Object.entries(groups).sort(([, a], [, b]) => best(b) - best(a)));
});

const sidedOutCards = computed<SidedOutCard[]>(() => (props.sideboard?.sidedOut ?? []) as SidedOutCard[]);

/**
 * The largest sample any community row is drawn from, used to caption the panel.
 * Rows can differ: the API counts a card's games only from the lists that ran
 * it, so a fringe one-of has a smaller denominator than a staple.
 */
const communityGames = computed<number | null>(() => {
    const samples = [...sidedInCards.value, ...sidedOutCards.value]
        .map((card) => card.communityGames)
        .filter((games): games is number => games !== null);

    return samples.length ? Math.max(...samples) : null;
});

/**
 * The share of games that has to include a card before the panel calls it a
 * recommendation rather than just a number. Half the games is the honest floor:
 * below it, siding the card is the minority line.
 */
const RECOMMEND_AT = 50;

/**
 * Whether to flag this card as one to bring in / take out.
 *
 * The field rate decides it when the API knows the card. Otherwise it falls
 * back to how often the player themselves has done it in this matchup, so a
 * card the API has never heard of still gets a call once there is local history
 * to make one from.
 */
const recommended = (communityRate: number | null, localGames: number): boolean => {
    if (communityRate !== null) {
        return communityRate >= RECOMMEND_AT;
    }

    const postboardGames = props.sideboard?.postboardGames ?? 0;

    return postboardGames > 0 && (localGames / postboardGames) * 100 >= RECOMMEND_AT;
};

const groupQuantity = (cards: SidedInCard[]): number => cards.reduce((sum, card) => sum + card.quantity, 0);

const communityTitle = (count: number | null, games: number | null, direction: 'in' | 'out'): string =>
    count === null || games === null ? 'No shared data for this card yet' : `Sided ${direction} in ${count} of ${games} shared games`;

const { hoveredCard, previewTop, onCardEnter, onCardLeave } = useCardHoverPreview<SidedInCard | SidedOutCard>();

const offlineMode = useOfflineMode();
</script>

<template>
    <div class="relative flex flex-col">
        <p v-if="emptyMessage" class="p-6 text-center text-xs text-muted-foreground">{{ emptyMessage }}</p>

        <template v-else-if="props.sideboard">
            <!-- Sample-size baseline, styled like the draw odds panel's header strip. -->
            <div class="px-4 py-2 text-[0.625rem] font-semibold tracking-wider text-muted-foreground/60 uppercase">
                <template v-if="props.sideboard.postboardGames > 0">
                    {{ props.sideboard.postboardGames }} post-board {{ props.sideboard.postboardGames === 1 ? 'game' : 'games' }} ·
                    {{ props.sideboard.postboardRecord }} overall
                </template>
                <template v-else>No games vs this archetype yet</template>
                <template v-if="communityGames"> · field = {{ communityGames }} shared games</template>
                <template v-else-if="offlineMode"> · field data unavailable in offline mode</template>
            </div>

            <div v-for="(cards, type) in groupedSidedIn" :key="type">
                <h3
                    class="flex items-baseline justify-between gap-2 border-y py-2 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
                >
                    <span class="min-w-0 truncate">{{ type }} ({{ groupQuantity(cards) }})</span>
                    <span class="flex shrink-0 items-center gap-2 px-2">
                        <span class="w-8" aria-hidden="true"></span>
                        <span class="w-10 text-right">Field</span>
                        <span class="w-20 text-right">Your W–L</span>
                    </span>
                </h3>
                <div class="divide-y text-xs">
                    <OverlayCardRow
                        v-for="card in cards"
                        :key="card.oracleId"
                        :name="card.name"
                        :count="card.quantity"
                        :art-crop="card.artCrop"
                        :class="{ 'opacity-40': card.sidedInGames === 0 && card.communityRate === null }"
                        @mouseenter="onCardEnter(card, $event)"
                        @mouseleave="onCardLeave"
                    >
                        <div class="flex shrink-0 items-center gap-2 px-2">
                            <span class="w-8 text-right text-[10px] font-bold tracking-wider uppercase">
                                <span v-if="recommended(card.communityRate, card.sidedInGames)" class="text-emerald-400">In</span>
                            </span>
                            <!-- How often the wider player base brings this in. Leads the
                                 row because it has a sample from the first game onward. -->
                            <span
                                class="w-10 text-right font-semibold tabular-nums"
                                :title="communityTitle(card.communitySidedIn, card.communityGames, 'in')"
                            >
                                <template v-if="card.communityRate !== null">{{ card.communityRate }}%</template>
                                <template v-else>—</template>
                            </span>
                            <span class="w-20 text-right font-medium text-muted-foreground tabular-nums">
                                <template v-if="card.sidedInGames > 0">{{ card.wins }}–{{ card.losses }} ({{ card.winrate }}%)</template>
                                <template v-else>—</template>
                            </span>
                        </div>
                    </OverlayCardRow>
                </div>
            </div>

            <template v-if="sidedOutCards.length">
                <h3
                    class="flex items-baseline justify-between gap-2 border-y py-2 pl-4 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase"
                >
                    <span class="min-w-0 truncate">Usually cut ({{ sidedOutCards.length }})</span>
                    <span class="flex shrink-0 items-center gap-2 px-2">
                        <span class="w-8" aria-hidden="true"></span>
                        <span class="w-10 text-right">Field</span>
                        <span class="w-20 text-right">You cut</span>
                    </span>
                </h3>
                <div class="divide-y text-xs">
                    <OverlayCardRow
                        v-for="card in sidedOutCards"
                        :key="card.oracleId"
                        :name="card.name"
                        :count="null"
                        :art-crop="card.artCrop"
                        @mouseenter="onCardEnter(card, $event)"
                        @mouseleave="onCardLeave"
                    >
                        <div class="flex shrink-0 items-center gap-2 px-2">
                            <span class="w-8 text-right text-[10px] font-bold tracking-wider uppercase">
                                <span v-if="recommended(card.communityRate, card.sidedOutGames)" class="text-red-400">Out</span>
                            </span>
                            <span
                                class="w-10 text-right font-semibold tabular-nums"
                                :title="communityTitle(card.communitySidedOut, card.communityGames, 'out')"
                            >
                                <template v-if="card.communityRate !== null">{{ card.communityRate }}%</template>
                                <template v-else>—</template>
                            </span>
                            <span class="w-20 text-right font-medium text-muted-foreground tabular-nums">
                                <template v-if="card.sidedOutGames > 0">{{ card.sidedOutGames }}× </template>
                                <template v-else>—</template>
                            </span>
                        </div>
                    </OverlayCardRow>
                </div>
            </template>
        </template>

        <div class="px-3 pt-3 pb-3">
            <ArchetypeNotes :current="props.notes.current" :other="props.notes.other" :disabled="!props.hasDeck || !props.hasArchetype" />
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
