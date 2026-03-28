<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckCardStats from '@/pages/decks/partials/DeckCardStats.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import CardStatsController from '@/actions/App/Http/Controllers/Decks/CardStatsController';
import { router } from '@inertiajs/vue3';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    cardStats?: any;
}>();

function setTimeframe(value: string) {
    const query: Record<string, string> = {};
    if (value !== 'alltime') query.timeframe = value;
    router.get(CardStatsController.url({ deck: props.deck.id }), query, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />
        <DeckCardStats :card-stats="cardStats" />
    </div>
</template>
