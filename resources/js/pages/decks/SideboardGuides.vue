<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckSideboardGuides from '@/pages/decks/partials/DeckSideboardGuides.vue';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

/**
 * `archetypes` is deferred by the controller (`Inertia::defer`) and absent from
 * the first response, so it is optional and defaulted.
 */
withDefaults(
    defineProps<{
        deck: App.Data.Front.DeckData;
        versions: VersionStats[];
        currentVersionId: number | null;
        trophies: number;
        currentPage: string;
        guides: App.Data.Front.SideboardGuideSummaryData[];
        archetypes?: App.Data.Front.ArchetypeData[];
    }>(),
    { archetypes: () => [] },
);
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <DeckSideboardGuides :deck="deck" :guides="guides" :archetypes="archetypes" />
    </div>
</template>
