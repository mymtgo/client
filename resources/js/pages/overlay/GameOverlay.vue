<script setup lang="ts">
import UpdateOpponentArchetypeController from '@/actions/App/Http/Controllers/Overlay/UpdateOpponentArchetypeController';
import DrawOddsPanel from '@/components/decks/DrawOddsPanel.vue';
import OpponentHeader from '@/components/overlay/OpponentHeader.vue';
import OverlayTabs from '@/components/overlay/OverlayTabs.vue';
import SideboardGuide from '@/components/overlay/SideboardGuide.vue';
import OverlayLayout from '@/Layouts/OverlayLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';

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
 * `archetypes` is deferred by the controller (`Inertia::defer`) and absent from
 * the first response — same shape as `resources/js/pages/leagues/Index.vue`'s
 * deferred `archetypes` prop. Follow that precedent: optional prop, defaulted
 * to an empty array via `withDefaults` rather than a local computed.
 */
const props = withDefaults(
    defineProps<{
        sections: { opponent: boolean; drawOdds: boolean; sideboard: boolean };
        opponent: App.Data.Front.OverlayOpponentData | null;
        archetypes?: App.Data.Front.ArchetypeData[];
        drawOdds: DrawOdds | null;
        sideboard: App.Data.Front.SideboardGuideData | null;
        notes: { current: App.Data.Front.ArchetypeNoteData[]; other: App.Data.Front.ArchetypeNoteData[] };
        isSideboarding: boolean;
        /** Whether a live match exists at all, independent of any section toggle. */
        hasMatch: boolean;
        /** Whether that match has a linked deck version, independent of any section toggle. */
        hasDeck: boolean;
        format?: string | null;
    }>(),
    { archetypes: () => [] },
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
 * its whole lifetime. `archetypes` is excluded: it is large and near-static.
 * Draw odds stays off the poll and reloads on its own event instead, so a tick
 * never rebuilds hypergeometric data mid-turn.
 */
usePoll(5000, {
    only: ['opponent', 'sideboard', 'notes', 'isSideboarding', 'sections', 'hasMatch', 'hasDeck', 'format'],
});

onMounted(() => {
    window.Native?.on('App\\Events\\GameCardsSnapshotChanged', () => {
        router.reload({ only: ['drawOdds'] });
    });
});

function selectArchetype(archetypeId: number): void {
    router.post(
        UpdateOpponentArchetypeController.url(),
        { archetype_id: archetypeId },
        { preserveScroll: true, only: ['opponent', 'sideboard', 'notes'] },
    );
}
</script>

<template>
    <div class="flex h-screen flex-col bg-background text-foreground">
        <OpponentHeader
            v-if="props.sections.opponent"
            :opponent="props.opponent"
            :archetypes="props.archetypes"
            :format="props.format ?? null"
            @select="selectArchetype"
        />

        <OverlayTabs
            v-model="activeTab"
            :show-draw-odds="props.sections.drawOdds"
            :show-sideboard="props.sections.sideboard"
        >
            <template #draw-odds>
                <DrawOddsPanel :draw-odds="props.drawOdds" />
            </template>

            <template #sideboard>
                <SideboardGuide
                    :sideboard="props.sideboard"
                    :notes="props.notes"
                    :has-match="props.hasMatch"
                    :has-deck="props.hasDeck"
                    :has-archetype="props.opponent?.archetypeId != null"
                />
            </template>
        </OverlayTabs>
    </div>
</template>
