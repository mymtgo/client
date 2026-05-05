<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckTournaments from '@/pages/decks/partials/DeckTournaments.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import TournamentsController from '@/actions/App/Http/Controllers/Decks/TournamentsController';
import { router } from '@inertiajs/vue3';
import type { VersionStats } from '@/types/decks';
import type { TournamentRun } from '@/types/tournaments';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    tournaments: TournamentRun[];
}>();

function setTimeframe(value: string) {
    const query: Record<string, string> = {};
    if (value !== 'alltime') query.timeframe = value;
    router.get(TournamentsController.url({ deck: props.deck.id }), query, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <TimeframeFilter
            :model-value="timeframe"
            @update:model-value="setTimeframe"
        />
        <DeckTournaments :tournaments="tournaments" />
    </div>
</template>
