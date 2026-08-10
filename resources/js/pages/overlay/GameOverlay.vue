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
        format?: string | null;
    }>(),
    { archetypes: () => [] },
);

const activeTab = ref('draw-odds');

/**
 * Suppresses auto-switching after the player picks a tab themselves. Cleared on
 * the next sideboarding transition so automation resumes for the following game.
 */
const tabPinned = ref(false);

watch(activeTab, () => (tabPinned.value = true));

watch(
    () => props.isSideboarding,
    (sideboarding, was) => {
        if (sideboarding === was) {
            return;
        }

        tabPinned.value = false;

        if (sideboarding && props.sections.sideboard) {
            activeTab.value = 'sideboard';
            tabPinned.value = false;
        }
    },
);

/**
 * The window is opened once at boot and never re-navigated, so polling runs for
 * its whole lifetime. `archetypes` is excluded: it is large and near-static.
 * Draw odds stays off the poll and reloads on its own event instead, so a tick
 * never rebuilds hypergeometric data mid-turn.
 */
usePoll(5000, { only: ['opponent', 'sideboard', 'notes', 'isSideboarding', 'sections'] });

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
                    :has-match="props.opponent !== null || props.drawOdds !== null"
                    :has-deck="props.drawOdds !== null || props.sideboard !== null"
                    :has-archetype="props.opponent?.archetypeId != null"
                />
            </template>
        </OverlayTabs>
    </div>
</template>
