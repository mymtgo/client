<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AppLayout from '@/AppLayout.vue';
import { Button } from '@/components/ui/button';
import DashboardLeague from '@/pages/partials/DashboardLeague.vue';
import DashboardFormatChart from '@/pages/partials/DashboardFormatChart.vue';
import DashboardDecks from '@/pages/partials/DashboardDecks.vue';
import DashboardRecentMatches from '@/pages/partials/DashboardRecentMatches.vue';

type ActiveLeague = {
    name: string;
    format: string;
    phantom: boolean;
    isActive: boolean;
    isTrophy: boolean;
    deckName: string | null;
    results: ('W' | 'L' | null)[];
    wins: number;
    losses: number;
    matchesRemaining: number;
};

type FormatChart = {
    months: string[];
    formats: string[];
    data: ({ x: number } & Record<string, number | null>)[];
};

type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: Record<string, unknown>;
};

const props = defineProps<{
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    recentMatches: Paginator<App.Data.Front.MatchData>;
    deckStats: App.Data.Front.DeckData[];
    timeframe: string;
    activeLeague: ActiveLeague | null;
    formatChart: FormatChart;
}>();

const timeframes = [
    { value: 'week', label: '7 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'monthly', label: '30 days' },
    { value: 'year', label: 'This year' },
    { value: 'alltime', label: 'All time' },
];

function setTimeframe(value: string) {
    router.get('/', { timeframe: value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="flex flex-col gap-6 p-4 lg:p-6">
            <!-- Timeframe selector -->
            <div class="flex items-center gap-1 self-start rounded-lg border p-1">
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

            <DashboardLeague :league="activeLeague" />
            <DashboardFormatChart :format-chart="formatChart" />
            <DashboardDecks :deck-stats="deckStats" />
            <DashboardRecentMatches :matches="recentMatches" />
        </div>
    </AppLayout>
</template>
