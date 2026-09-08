<script setup lang="ts">
import MatchesController from '@/actions/App/Http/Controllers/Limited/MatchesController';
import AppLayout from '@/AppLayout.vue';
import LimitedEventLayout from '@/Layouts/LimitedEventLayout.vue';
import MatchDetail from '@/pages/matches/partials/MatchDetail.vue';
import type { GameDetail, ManualEditingData } from '@/types/matches';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: [AppLayout, LimitedEventLayout] });

const props = defineProps<{
    event: App.Data.Front.LimitedEventData;
    currentPage: string;
    match: App.Data.Front.MatchData;
    games: GameDetail[];
    gameLogs: Record<number, Array<{ timestamp: string; message: string }>>;
    archetypes: App.Data.Front.ArchetypeData[];
    imported: boolean;
    manual: boolean;
    manualEditing: ManualEditingData | null;
}>();
</script>

<template>
    <div>
        <Head :title="`${event.title} · Match`" />
        <MatchDetail
            :match="match"
            :games="games"
            :game-logs="gameLogs"
            :archetypes="archetypes"
            :imported="imported"
            :manual="manual"
            :manual-editing="manualEditing"
            :fallback-url="MatchesController.url({ league: props.event.id })"
        />
    </div>
</template>
