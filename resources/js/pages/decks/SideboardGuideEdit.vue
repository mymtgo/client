<script setup lang="ts">
import UpdateCardsController from '@/actions/App/Http/Controllers/Decks/SideboardGuides/UpdateCardsController';
import SideboardGuidesController from '@/actions/App/Http/Controllers/Decks/SideboardGuidesController';
import AppLayout from '@/AppLayout.vue';
import CardHoverPreview from '@/components/cards/CardHoverPreview.vue';
import SideboardGuideCardRow from '@/components/decks/sideboard-guides/SideboardGuideCardRow.vue';
import SideboardGuideNotes from '@/components/decks/sideboard-guides/SideboardGuideNotes.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Button } from '@/components/ui/button';
import { groupByType } from '@/composables/useCardTypeGroups';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import type { VersionStats } from '@/types/decks';
import type { GuideCardInput, GuideInCard, GuideOutCard } from '@/types/sideboardGuides';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    guide: App.Data.Front.SideboardGuideSummaryData;
    hasVersion: boolean;
    sideboard: App.Data.Front.SideboardGuideData | null;
    notes: { current: App.Data.Front.ArchetypeNoteData[]; other: App.Data.Front.ArchetypeNoteData[] };
}>();

const inCards = computed<GuideInCard[]>(() => (props.sideboard?.sidedIn ?? []) as GuideInCard[]);
const outCards = computed<GuideOutCard[]>(() => (props.sideboard?.sidedOut ?? []) as GuideOutCard[]);

/** Planned copies keyed "in:<oracleId>" / "out:<oracleId>". */
const selection = reactive<Record<string, number>>({});

function seed(): void {
    for (const key of Object.keys(selection)) delete selection[key];
    for (const card of inCards.value) if (card.plannedQuantity) selection[`in:${card.oracleId}`] = card.plannedQuantity;
    for (const card of outCards.value) if (card.plannedQuantity) selection[`out:${card.oracleId}`] = card.plannedQuantity;
}

seed();

function build(): GuideCardInput[] {
    return Object.entries(selection)
        .filter(([, quantity]) => quantity > 0)
        .map(([key, quantity]) => {
            const idx = key.indexOf(':');
            const direction = key.slice(0, idx) as 'in' | 'out';
            const oracleId = key.slice(idx + 1);
            return { oracle_id: oracleId, direction, quantity };
        })
        .sort((a, b) => a.direction.localeCompare(b.direction) || a.oracle_id.localeCompare(b.oracle_id));
}

const totalIn = computed(() =>
    Object.entries(selection)
        .filter(([k]) => k.startsWith('in:'))
        .reduce((sum, [, q]) => sum + q, 0),
);
const totalOut = computed(() =>
    Object.entries(selection)
        .filter(([k]) => k.startsWith('out:'))
        .reduce((sum, [, q]) => sum + q, 0),
);
const balanced = computed(() => totalIn.value === totalOut.value);

/**
 * Card changes autosave: each stepper click restarts a short debounce, then the
 * whole selection is PUT. Requests run async so a navigation started mid-save
 * does not cancel them, and a change made while one is in flight saves again
 * once it finishes. The selection stays local truth; only a validation error
 * reverts it to what the server holds.
 */
const SAVE_DELAY_MS = 500;
const saveState = ref<'idle' | 'pending' | 'saving' | 'saved' | 'error'>('idle');
const saveError = ref<string | null>(null);
let saveTimer: ReturnType<typeof setTimeout> | null = null;
let inFlight = false;
let lastSent = JSON.stringify(build());

function scheduleSave(): void {
    if (saveTimer) clearTimeout(saveTimer);
    saveState.value = 'pending';
    saveTimer = setTimeout(flushSave, SAVE_DELAY_MS);
}

function flushSave(): void {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = null;
    if (inFlight) return;

    const cards = build();
    const payload = JSON.stringify(cards);
    if (payload === lastSent) {
        if (saveState.value === 'pending') saveState.value = 'saved';
        return;
    }

    inFlight = true;
    saveState.value = 'saving';
    saveError.value = null;
    router.put(
        UpdateCardsController.url({ deck: props.deck.id, sideboardGuide: props.guide.id }),
        { cards },
        {
            preserveScroll: true,
            async: true,
            only: ['sideboard', 'guide'],
            onSuccess: () => {
                lastSent = payload;
                saveState.value = 'saved';
            },
            onError: (errors) => {
                saveError.value = Object.values(errors)[0] ?? 'Could not save your changes.';
                saveState.value = 'error';
                seed();
                lastSent = JSON.stringify(build());
            },
            onFinish: () => {
                inFlight = false;
                if (JSON.stringify(build()) !== lastSent) flushSave();
            },
        },
    );
}

function setPlanned(direction: 'in' | 'out', oracleId: string, value: number): void {
    const key = `${direction}:${oracleId}`;
    if (value === 0) delete selection[key];
    else selection[key] = value;
    scheduleSave();
}

/** Leaving the page sends any debounced change straight away. */
const detach = router.on('before', (event) => {
    if (event.detail.visit.prefetch) return;
    if (saveTimer) flushSave();
});
onBeforeUnmount(() => {
    detach();
    if (saveTimer) flushSave();
});

const saveLabel = computed(() => {
    switch (saveState.value) {
        case 'pending':
        case 'saving':
            return 'Saving…';
        case 'saved':
            return 'Saved';
        case 'error':
            return 'Not saved';
        default:
            return null;
    }
});

const groupedIn = computed(() => groupByType(inCards.value, (card) => card.type));
const groupedOut = computed(() => groupByType(outCards.value, (card) => card.type));

const record = computed(() => {
    if (props.guide.matches === 0) return 'Not faced yet';
    return `${props.guide.matchRecord} · ${props.guide.matchWinrate}% match · ${props.guide.gameWinrate}% game`;
});

const communityCaption = computed(() => {
    const samples = [...inCards.value, ...outCards.value].map((c) => c.communityGames).filter((g): g is number => g !== null);
    return samples.length ? `Field = ${Math.max(...samples)} shared games` : null;
});
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Button variant="ghost" size="icon" class="size-8" as-child>
                    <Link :href="SideboardGuidesController.url({ deck: deck.id })" aria-label="Back to guides"><ArrowLeft class="size-4" /></Link>
                </Button>
                <div class="flex flex-col gap-0.5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <ManaSymbols :symbols="guide.archetypeColorIdentity" />
                        vs {{ guide.archetypeName }}
                    </h2>
                    <p class="text-xs text-muted-foreground tabular-nums">{{ record }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span
                    class="rounded-md border px-2 py-1 text-xs font-semibold tabular-nums"
                    :class="balanced ? 'border-border text-muted-foreground' : 'border-amber-500/40 bg-amber-500/10 text-amber-300'"
                    :title="balanced ? 'In and out are balanced' : 'Cards in and out do not match'"
                >
                    +{{ totalIn }} / -{{ totalOut }}
                </span>
                <span
                    v-if="saveLabel"
                    class="text-xs tabular-nums"
                    :class="saveState === 'error' ? 'text-red-300' : 'text-muted-foreground'"
                    aria-live="polite"
                >
                    {{ saveLabel }}
                </span>
            </div>
        </header>

        <p v-if="saveError" class="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-300">
            {{ saveError }}
        </p>

        <SideboardGuideNotes :deck-id="deck.id" :guide-id="guide.id" :current="notes.current" :other="notes.other" />

        <h3 class="border-t border-border pt-4 text-sm font-semibold">Sideboarding</h3>

        <p v-if="!hasVersion" class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
            This deck has no saved version yet, so there is nothing to plan with. Notes still work above.
        </p>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <section class="flex flex-col gap-2 rounded-md border border-border">
                <h3
                    class="flex items-baseline justify-between border-b border-border px-3 py-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <span>Bring in</span>
                    <span class="flex gap-2">
                        <span class="w-10 text-right">Field</span>
                        <span class="w-20 text-right">Your W–L</span>
                    </span>
                </h3>
                <p v-if="inCards.length === 0" class="px-3 pb-3 text-xs text-muted-foreground">No sideboard cards in the current list.</p>
                <div v-for="(cards, type) in groupedIn" :key="type" class="px-3">
                    <p class="py-1 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase">{{ type }}</p>
                    <div class="divide-y divide-border/60">
                        <CardHoverPreview v-for="card in cards" :key="card.oracleId" :image="card.image" :name="card.name">
                            <SideboardGuideCardRow
                                :name="card.name"
                                :art-crop="card.artCrop"
                                :owned="card.quantity"
                                :planned="selection[`in:${card.oracleId}`] ?? 0"
                                :stale="card.stale"
                                direction="in"
                                @change="(value) => setPlanned('in', card.oracleId, value)"
                            >
                                <span class="flex shrink-0 gap-2 text-xs tabular-nums">
                                    <span class="w-10 text-right font-semibold">
                                        <template v-if="card.communityRate !== null">{{ card.communityRate }}%</template>
                                        <template v-else>—</template>
                                    </span>
                                    <span class="w-20 text-right text-muted-foreground">
                                        <template v-if="card.sidedInGames > 0">{{ card.wins }}–{{ card.losses }} ({{ card.winrate }}%)</template>
                                        <template v-else>—</template>
                                    </span>
                                </span>
                            </SideboardGuideCardRow>
                        </CardHoverPreview>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-2 rounded-md border border-border">
                <h3
                    class="flex items-baseline justify-between border-b border-border px-3 py-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <span>Take out</span>
                    <span class="flex gap-2">
                        <span class="w-10 text-right">Field</span>
                        <span class="w-20 text-right">You cut</span>
                    </span>
                </h3>
                <div v-for="(cards, type) in groupedOut" :key="type" class="px-3">
                    <p class="py-1 text-[10px] font-semibold tracking-wider text-muted-foreground/60 uppercase">{{ type }}</p>
                    <div class="divide-y divide-border/60">
                        <CardHoverPreview v-for="card in cards" :key="card.oracleId" :image="card.image" :name="card.name">
                            <SideboardGuideCardRow
                                :name="card.name"
                                :art-crop="card.artCrop"
                                :owned="card.quantity"
                                :planned="selection[`out:${card.oracleId}`] ?? 0"
                                :stale="card.stale"
                                direction="out"
                                @change="(value) => setPlanned('out', card.oracleId, value)"
                            >
                                <span class="flex shrink-0 gap-2 text-xs tabular-nums">
                                    <span class="w-10 text-right font-semibold">
                                        <template v-if="card.communityRate !== null">{{ card.communityRate }}%</template>
                                        <template v-else>—</template>
                                    </span>
                                    <span class="w-20 text-right text-muted-foreground">
                                        <template v-if="card.sidedOutGames > 0">{{ card.sidedOutGames }}×</template>
                                        <template v-else>—</template>
                                    </span>
                                </span>
                            </SideboardGuideCardRow>
                        </CardHoverPreview>
                    </div>
                </div>
            </section>
        </div>

        <p v-if="sideboard" class="text-[10px] tracking-wider text-muted-foreground/60 uppercase">
            <template v-if="sideboard.postboardGames > 0"
                >{{ sideboard.postboardGames }} post-board games · {{ sideboard.postboardRecord }} overall</template
            >
            <template v-else>No post-board games vs this archetype yet</template>
            <template v-if="communityCaption"> · {{ communityCaption }}</template>
        </p>
    </div>
</template>
