<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DashboardLeague from '@/pages/partials/DashboardLeague.vue';
import DashboardFormatChart from '@/pages/partials/DashboardFormatChart.vue';
import DashboardDecks from '@/pages/partials/DashboardDecks.vue';
import DashboardRecentMatches from '@/pages/partials/DashboardRecentMatches.vue';

// Props kept for future backend wiring — not consumed while fake data is in use
defineProps<{
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    recentMatches: App.Data.Front.MatchData[];
    deckStats: App.Data.Front.DeckData[];
    timeframe: string;
}>();
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="flex flex-col gap-6 p-4 lg:p-6">
            <DashboardLeague />
            <DashboardFormatChart />
            <DashboardDecks />
            <DashboardRecentMatches />
        </div>
    </AppLayout>
</template>
