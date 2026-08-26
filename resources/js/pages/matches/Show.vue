<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import MatchesController from '@/actions/App/Http/Controllers/Decks/MatchesController';
import MatchDetail from '@/pages/matches/partials/MatchDetail.vue';
import type { VersionStats } from '@/types/decks';
import type { GameDetail } from '@/types/matches';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    match: App.Data.Front.MatchData;
    games: GameDetail[];
    gameLogs: Record<number, Array<{ timestamp: string; message: string }>>;
    archetypes: App.Data.Front.ArchetypeData[];
    imported: boolean;
}>();
</script>

<template>
    <MatchDetail
        :match="match"
        :games="games"
        :game-logs="gameLogs"
        :archetypes="archetypes"
        :imported="imported"
        :fallback-url="MatchesController.url(props.deck)"
    />
</template>
