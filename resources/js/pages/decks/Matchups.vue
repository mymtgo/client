<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckMatchups from '@/pages/decks/partials/DeckMatchups.vue';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    matchupSpread: any[];
}>();
</script>

<template>
    <div class="p-3 lg:p-4">
        <DeckMatchups :matchup-spread="matchupSpread" />
    </div>
</template>
