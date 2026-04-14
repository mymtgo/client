<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckMatchups from '@/pages/decks/partials/DeckMatchups.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import MatchupsController from '@/actions/App/Http/Controllers/Decks/MatchupsController';
import { router } from '@inertiajs/vue3';
import type { VersionStats, MatchupSpread } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    matchupSpread: MatchupSpread[];
}>();

function setTimeframe(value: string) {
    const query: Record<string, string> = {};
    if (value !== 'alltime') query.timeframe = value;
    router.get(MatchupsController.url({ deck: props.deck.id }), query, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />
        <DeckMatchups
            :matchup-spread="matchupSpread"
            :deck-id="deck.id"
            :timeframe="timeframe"
            :version="currentVersionId"
        />
    </div>
</template>
