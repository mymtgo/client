<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckDashboard from '@/pages/decks/partials/DeckDashboard.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import DashboardController from '@/actions/App/Http/Controllers/Decks/DashboardController';
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
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    gamesOtpWon: number;
    gamesOtpLost: number;
    otpRate: number;
    gamesOtdWon: number;
    gamesOtdLost: number;
    otdRate: number;
    chartData: { date: string; wins: number; losses: number; winrate: string | null }[];
    matchupSpread?: any[];
    leagueResults?: Record<string, number>;
}>();

function setTimeframe(value: string) {
    const query: Record<string, string> = {};
    if (value !== 'alltime') query.timeframe = value;
    router.get(DashboardController.url({ deck: props.deck.id }), query, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />
        <DeckDashboard
            :matches-won="matchesWon"
            :matches-lost="matchesLost"
            :match-winrate="matchWinrate"
            :games-won="gamesWon"
            :games-lost="gamesLost"
            :game-winrate="gameWinrate"
            :games-otp-won="gamesOtpWon"
            :games-otp-lost="gamesOtpLost"
            :otp-rate="otpRate"
            :games-otd-won="gamesOtdWon"
            :games-otd-lost="gamesOtdLost"
            :otd-rate="otdRate"
            :chart-data="chartData"
            :matchup-spread="matchupSpread"
            :league-results="leagueResults"
            :deck-id="deck.id"
            :timeframe="timeframe"
        />
    </div>
</template>
