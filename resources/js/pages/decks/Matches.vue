<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckMatches from '@/pages/decks/partials/DeckMatches.vue';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    matches: any;
    archetypes: any[];
}>();
</script>

<template>
    <div class="p-3 lg:p-4">
        <DeckMatches :matches="matches" :archetypes="archetypes" />
    </div>
</template>
