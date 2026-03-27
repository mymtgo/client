<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckMatchups from '@/pages/decks/partials/DeckMatchups.vue';
import { Button } from '@/components/ui/button';
import MatchupsController from '@/actions/App/Http/Controllers/Decks/MatchupsController';
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
    matchupSpread: any[];
}>();

const timeframes = [
    { value: 'week', label: '7 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'monthly', label: '30 days' },
    { value: 'year', label: 'This year' },
    { value: 'alltime', label: 'All time' },
];

function setTimeframe(value: string) {
    const query: Record<string, string> = {};
    if (value !== 'alltime') query.timeframe = value;
    router.get(MatchupsController.url({ deck: props.deck.id }), query, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <div class="flex items-center gap-1 rounded-md border p-1 self-start">
            <Button
                v-for="tf in timeframes"
                :key="tf.value"
                size="sm"
                :variant="timeframe === tf.value ? 'default' : 'ghost'"
                class="h-7 px-3 text-xs"
                @click="setTimeframe(tf.value)"
            >
                {{ tf.label }}
            </Button>
        </div>

        <DeckMatchups :matchup-spread="matchupSpread" />
    </div>
</template>
