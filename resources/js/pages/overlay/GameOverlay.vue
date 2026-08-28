<script setup lang="ts">
import FitGameOverlayWindowController from '@/actions/App/Http/Controllers/Overlay/FitGameOverlayWindowController';
import UpdateOpponentArchetypeController from '@/actions/App/Http/Controllers/Overlay/UpdateOpponentArchetypeController';
import DrawOddsPanel from '@/components/decks/DrawOddsPanel.vue';
import OpponentHeader from '@/components/overlay/OpponentHeader.vue';
import OverlayTabs from '@/components/overlay/OverlayTabs.vue';
import RevealedCards from '@/components/overlay/RevealedCards.vue';
import SideboardGuide from '@/components/overlay/SideboardGuide.vue';
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({ layout: OverlayLayout });

/**
 * The backend serializes `cards` as a plain array at runtime, but the generated
 * `DrawOddsData` type describes it as a keyed record (a Spatie DataCollection
 * artifact). Override that member so the prop passes straight to DrawOddsPanel.
 */
type DrawOdds = Omit<App.Data.Front.DrawOddsData, 'cards'> & {
    cards: App.Data.Front.DrawOddsCardData[];
};

/**
 * `archetypes` and `drawOdds` are both deferred by the controller
 * (`Inertia::defer`) and absent from the first response — same shape as
 * `resources/js/pages/leagues/Index.vue`'s deferred `archetypes` prop. Follow
 * that precedent: optional props, defaulted via `withDefaults` rather than a
 * local computed.
 */
const props = withDefaults(
    defineProps<{
        sections: { opponent: boolean; drawOdds: boolean; sideboard: boolean; reveals: boolean };
        opponent: App.Data.Front.OverlayOpponentData | null;
        archetypes?: App.Data.Front.ArchetypeData[];
        drawOdds?: DrawOdds | null;
        sideboard: App.Data.Front.SideboardGuideData | null;
        reveals: App.Data.Front.RevealedCardData[] | null;
        notes: { current: App.Data.Front.ArchetypeNoteData[]; other: App.Data.Front.ArchetypeNoteData[] };
        isSideboarding: boolean;
        /** Whether a live match exists at all, independent of any section toggle. */
        hasMatch: boolean;
        /** Whether that match has a linked deck version, independent of any section toggle. */
        hasDeck: boolean;
        /**
         * Whether an opponent archetype resolved, independent of any section
         * toggle — `opponent` is null whenever the opponent header is off, so
         * the sideboard panel cannot read this off the opponent payload.
         */
        hasArchetype: boolean;
        format?: string | null;
    }>(),
    { archetypes: () => [], drawOdds: null },
);

const activeTab = ref('draw-odds');

/**
 * Switches to the sideboard tab the moment sideboarding begins. Vue's `watch`
 * only invokes this callback when `isSideboarding` actually changes, so this
 * fires once per transition — never on every poll tick that merely confirms
 * the same value. Leaving sideboarding (true → false, once the next game
 * starts) intentionally does nothing: whatever tab the player is on carries
 * over into the next game instead of being yanked back to draw odds.
 */
watch(
    () => props.isSideboarding,
    (sideboarding) => {
        if (sideboarding && props.sections.sideboard) {
            activeTab.value = 'sideboard';
        }
    },
);

/**
 * The window is opened once at boot and never re-navigated, so polling runs for
 * its whole lifetime. `archetypes` and `drawOdds` are both excluded, and both
 * are deferred server-side so a tick does not compute them only to discard
 * them: naming a prop in `only` is what decides whether a deferred closure
 * runs at all. Draw odds reloads on its own event instead, so a tick never
 * rebuilds hypergeometric data mid-turn.
 */
usePoll(5000, {
    only: ['opponent', 'sideboard', 'reveals', 'notes', 'isSideboarding', 'sections', 'hasMatch', 'hasDeck', 'hasArchetype', 'format', 'offlineMode'],
});

onMounted(() => {
    window.Native?.on('App\\Events\\GameCardsSnapshotChanged', () => {
        router.reload({ only: ['drawOdds'] });
    });
});

const hasTabSections = computed(() => props.sections.drawOdds || props.sections.reveals || props.sections.sideboard);

/**
 * The window height is `rememberState()`-restored, so a height saved while
 * every section was on would leave an opponent-only overlay mostly empty. The
 * page measures its fixed region (the header, or the settings hint) and asks
 * the backend to size the window around it; the tab area, when present, gets
 * a fixed default because it scrolls internally and the player sizes that
 * part by hand. The fit runs on mount and whenever the enabled sections change
 * (settings are picked up by the poll), never on a plain manual resize.
 *
 * Without a tab section the window has nothing to absorb a taller header, so
 * it also follows the header as it changes shape (no opponent yet → opponent
 * resolved → archetype picked) via a ResizeObserver.
 */
const fixedRegion = ref<HTMLElement | null>(null);
let lastSentFixedHeight: number | null = null;
let fitTimer: ReturnType<typeof setTimeout> | null = null;

function fitWindow(): void {
    const measured = Math.round(fixedRegion.value?.offsetHeight ?? 0);
    if (measured === lastSentFixedHeight) return;
    lastSentFixedHeight = measured;

    router.post(FitGameOverlayWindowController.url(), { fixed_height: measured }, { preserveScroll: true, preserveState: true, only: ['sections'] });
}

function scheduleFit(): void {
    if (fitTimer) clearTimeout(fitTimer);
    fitTimer = setTimeout(fitWindow, 100);
}

const fixedRegionObserver = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(scheduleFit);

watch(
    hasTabSections,
    (tabbed) => {
        fixedRegionObserver?.disconnect();
        if (!tabbed && fixedRegion.value) {
            fixedRegionObserver?.observe(fixedRegion.value);
        }
    },
    { flush: 'post' },
);

watch(
    () => JSON.stringify(props.sections),
    () => {
        // A new section set changes the target height even when the fixed
        // region measures the same, so force the next fit through.
        lastSentFixedHeight = null;
        scheduleFit();
    },
    { flush: 'post' },
);

onMounted(() => {
    if (!hasTabSections.value && fixedRegion.value) {
        fixedRegionObserver?.observe(fixedRegion.value);
    }
    scheduleFit();
});

onBeforeUnmount(() => {
    fixedRegionObserver?.disconnect();
    if (fitTimer) clearTimeout(fitTimer);
});

function selectArchetype(archetypeId: number): void {
    router.post(
        UpdateOpponentArchetypeController.url(),
        { archetype_id: archetypeId },
        { preserveScroll: true, only: ['opponent', 'sideboard', 'notes', 'hasArchetype'] },
    );
}
</script>

<template>
    <div class="flex h-screen flex-col bg-background text-foreground">
        <div ref="fixedRegion" class="shrink-0">
            <OpponentHeader
                v-if="props.sections.opponent"
                :opponent="props.opponent"
                :archetypes="props.archetypes"
                :format="props.format ?? null"
                @select="selectArchetype"
            />

            <p v-if="!hasTabSections" class="p-3 text-center text-xs text-muted-foreground">
                Enable draw odds, revealed cards, or the sideboard guide in Settings.
            </p>
        </div>

        <OverlayTabs
            v-if="hasTabSections"
            v-model="activeTab"
            :show-draw-odds="props.sections.drawOdds"
            :show-sideboard="props.sections.sideboard"
            :show-reveals="props.sections.reveals"
        >
            <template #draw-odds>
                <DrawOddsPanel :draw-odds="props.drawOdds" />
            </template>

            <template #reveals>
                <RevealedCards :reveals="props.reveals" :has-match="props.hasMatch" />
            </template>

            <template #sideboard>
                <SideboardGuide
                    :sideboard="props.sideboard"
                    :notes="props.notes"
                    :has-match="props.hasMatch"
                    :has-deck="props.hasDeck"
                    :has-archetype="props.hasArchetype"
                />
            </template>
        </OverlayTabs>
    </div>
</template>
