<script setup lang="ts">
import DraftController from '@/actions/App/Http/Controllers/Limited/DraftController';
import AppLayout from '@/AppLayout.vue';
import ManaPips from '@/components/limited/ManaPips.vue';
import Card from '@/components/ui/card/Card.vue';
import LimitedEventLayout from '@/Layouts/LimitedEventLayout.vue';
import CrossDraftCard from '@/pages/limited/partials/CrossDraftCard.vue';
import PickDetail from '@/pages/limited/partials/PickDetail.vue';
import PickNoteEditor from '@/pages/limited/partials/PickNoteEditor.vue';
import SignalsPanel from '@/pages/limited/partials/SignalsPanel.vue';
import { cardFor, COLOR_ORDER, formatSeconds, NO_VALUE, type CrossDraftStats, type DraftReview } from '@/types/limited';
import { Head } from '@inertiajs/vue3';
import { BookOpen } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: [AppLayout, LimitedEventLayout] });

const props = defineProps<{
    event: App.Data.Front.LimitedEventData;
    currentPage: string;
    review: DraftReview | null;
    selectedOrdinal: number | null;
    crossDraft?: CrossDraftStats;
}>();

const selected = ref<number>(props.selectedOrdinal ?? 1);
const selectedCard = ref<number | null>(null);

const pick = computed(() => props.review?.picks.find((candidate) => candidate.ordinal === selected.value) ?? null);

function select(ordinal: number) {
    selected.value = ordinal;
    selectedCard.value = null;
}

/**
 * Keep the URL shareable without a server round trip. Inertia's router.replace
 * is a client-side visit that swaps props, which would throw the payload away.
 */
watch(selected, (ordinal) => {
    if (!props.review) {
        return;
    }
    const url = DraftController.url({ league: props.event.id }, { query: { pick: ordinal } });
    window.history.replaceState(window.history.state, '', url);
});

/** Hand-sign the m:ss form so a negative margin reads as "-1:30" rather than "1:30". */
const avgMarginLabel = computed(() => {
    const seconds = props.review?.header.avgMarginSeconds ?? null;
    if (seconds === null) {
        return NO_VALUE;
    }
    return seconds < 0 ? `-${formatSeconds(Math.abs(seconds))}` : formatSeconds(seconds);
});

const colorsPicked = computed(() => {
    const tally = props.review?.header.colorsPicked ?? {};
    return [...COLOR_ORDER, 'C' as const].filter((color) => (tally[color] ?? 0) > 0).map((color) => ({ color, count: tally[color] ?? 0 }));
});
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <Head :title="`${event.title} · Draft`" />

        <div v-if="!review" class="flex flex-col items-center gap-2 py-16 text-center">
            <BookOpen class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No draft recorded for this event.</p>
            <p class="text-sm text-muted-foreground">Picks appear here once mymtgo sees a draft for this league.</p>
        </div>

        <template v-else>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col">
                    <h1 class="text-base font-semibold tracking-tight">Draft review</h1>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="review.header.seatIndex !== null"
                            >Seat {{ review.header.seatIndex + 1 }} of {{ review.header.seatCount }} ·
                        </span>
                        <span v-if="review.header.boosterCatalogId">booster {{ review.header.boosterCatalogId }} · </span>
                        {{ event.setCode ?? '?' }} × 3 · {{ review.header.packSize }} cards per pack
                        <span v-if="review.header.durationMinutes !== null"> · {{ review.header.durationMinutes }} min</span>
                    </p>
                </div>
                <div class="flex items-center gap-6 text-right">
                    <div class="flex flex-col">
                        <span class="text-[10px] tracking-wide text-muted-foreground uppercase">avg pick</span>
                        <span class="text-lg font-bold tabular-nums">{{ formatSeconds(review.header.avgPickSeconds) }}</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-[10px] tracking-wide text-muted-foreground uppercase">timer margin</span>
                        <span class="text-lg font-bold tabular-nums">{{ avgMarginLabel }}</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-[10px] tracking-wide text-muted-foreground uppercase">indecision</span>
                        <span class="text-lg font-bold tabular-nums">{{ review.header.indecisiveCount }}/{{ review.header.picksMade }}</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-[10px] tracking-wide text-muted-foreground uppercase">colours picked</span>
                        <span v-if="colorsPicked.length" class="flex items-center gap-2 text-sm font-semibold tabular-nums">
                            <span v-for="entry in colorsPicked" :key="entry.color" class="inline-flex items-center gap-1">
                                <ManaPips :colors="entry.color === 'C' ? '' : entry.color" size="md" />{{ entry.count }}
                            </span>
                        </span>
                        <span v-else class="text-lg font-bold">{{ NO_VALUE }}</span>
                    </div>
                </div>
            </div>

            <Card class="gap-0 py-0">
                <SignalsPanel :signals="review.signals" />
            </Card>

            <div v-if="pick" class="grid gap-4 lg:grid-cols-[1fr_340px]">
                <PickDetail
                    :pick="pick"
                    :picks="review.picks"
                    :cards="review.cards"
                    :seen-wheel="review.seenWheel"
                    :pack-size="review.header.packSize"
                    @select="select"
                    @select-card="(id) => (selectedCard = id)"
                />

                <div class="flex flex-col gap-4">
                    <PickNoteEditor :league-id="event.id" :pick="pick" />
                    <CrossDraftCard
                        :card="
                            selectedCard !== null
                                ? cardFor(review.cards, selectedCard)
                                : pick.pickedCatalogId !== null
                                  ? cardFor(review.cards, pick.pickedCatalogId)
                                  : null
                        "
                        :set-code="event.setCode"
                        :stats="crossDraft"
                        :pack-size="review.header.packSize"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
