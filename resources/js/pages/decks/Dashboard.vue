<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import DeckDashboard from '@/pages/decks/partials/DeckDashboard.vue';
import type { VersionStats } from '@/types/decks';
import { computed } from 'vue';

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

const realVersions = computed(() => props.versions.filter((v) => v.id !== null));
const activeVersion = computed((): VersionStats => {
    return realVersions.value.find((v) => v.id === props.currentVersionId) ?? realVersions.value[realVersions.value.length - 1];
});
</script>

<template>
    <div class="p-3 lg:p-4">
        <DeckDashboard
            :active-version="activeVersion"
            :chart-data="chartData"
            :matchup-spread="matchupSpread"
            :league-results="leagueResults"
            :deck-id="deck.id"
            :timeframe="timeframe"
        />
    </div>
</template>
