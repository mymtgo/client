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
    matchRecord: App.Data.Front.MatchRecordData;
    gamesWon: number;
    gamesLost: number;
    gameWinrate: number;
    gamesOtpWon: number;
    gamesOtpLost: number;
    otpRate: number;
    gamesOtdWon: number;
    gamesOtdLost: number;
    otdRate: number;
    playDrawGames: number;
    winrateDelta: { previousRate: number | null; previousTotal: number; delta: number | null };
    chartData: { date: string; wins: number; losses: number; draws: number; winrate: string | null }[];
    peerChart?: { archetypeName: string; deckCount: number; data: { date: string; wins: number; losses: number; draws: number }[] } | null;
    matchupSpread?: any[];
    leagueResults?: Record<string, number>;
    standoutCards?: Record<string, any>;
    latestLeague?: any;
    boardingSplit?: any;
    leagueInProgress?: any;
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
            :match-record="matchRecord"
            :games-won="gamesWon"
            :games-lost="gamesLost"
            :game-winrate="gameWinrate"
            :games-otp-won="gamesOtpWon"
            :games-otp-lost="gamesOtpLost"
            :otp-rate="otpRate"
            :games-otd-won="gamesOtdWon"
            :games-otd-lost="gamesOtdLost"
            :otd-rate="otdRate"
            :play-draw-games="playDrawGames"
            :timeframe="timeframe"
            :winrate-delta="winrateDelta"
            :chart-data="chartData"
            :peer-chart="peerChart ?? null"
            :matchup-spread="matchupSpread"
            :league-results="leagueResults"
            :standout-cards="standoutCards"
            :latest-league="latestLeague"
            :boarding-split="boardingSplit"
            :league-in-progress="leagueInProgress"
        />
    </div>
</template>
