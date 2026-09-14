<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Deferred } from '@inertiajs/vue3';
import MatchHistoryChart from '@/pages/decks/partials/MatchHistoryChart.vue';
import StandoutCards from '@/pages/decks/partials/StandoutCards.vue';
import KpiMatchWinRate from '@/pages/decks/partials/KpiMatchWinRate.vue';
import KpiBoardingSplit from '@/pages/decks/partials/KpiBoardingSplit.vue';
import KpiPlayDrawGap from '@/pages/decks/partials/KpiPlayDrawGap.vue';
import KpiBestWorstMatchups from '@/pages/decks/partials/KpiBestWorstMatchups.vue';
import LeagueRunCard from '@/pages/decks/partials/LeagueRunCard.vue';
import type { LeagueRun } from '@/types/leagues';
import { computed } from 'vue';

const props = defineProps<{
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
    timeframe: string;
    winrateDelta: { previousRate: number | null; previousTotal: number; delta: number | null };
    chartData: { date: string; wins: number; losses: number; draws: number; winrate: string | null }[];
    peerChart?: { archetypeName: string; deckCount: number; data: { date: string; wins: number; losses: number; draws: number }[] } | null;
    matchupSpread?: any[];
    leagueResults?: Record<string, number>;
    standoutCards?: Record<string, any>;
    latestLeague?: LeagueRun;
    boardingSplit?: any;
    leagueInProgress?: any;
}>();

const TIMEFRAME_LABELS: Record<string, string> = {
    week: '7d',
    biweekly: '2w',
    monthly: '30d',
    year: 'This year',
    alltime: 'All time',
};

const timeframeLabel = computed(() => TIMEFRAME_LABELS[props.timeframe] ?? 'All time');

const totalGames = computed(() => props.gamesWon + props.gamesLost);

/** A run still being played outranks a finished one; only one is ever shown. */
const currentLeagueRun = computed(() => props.leagueInProgress ?? props.latestLeague ?? null);

const activeLeagueResults = computed(() => props.leagueResults ?? { '5-0': 0, '4-1': 0, '3-2': 0, '2-3': 0, '1-4': 0, '0-5': 0 });

const leagueResultsTotal = computed(() => {
    const sum = Object.values(activeLeagueResults.value).reduce((a, b) => a + b, 0);
    return sum || 1;
});

const leagueResultsBuckets = ['5-0', '4-1', '3-2', '2-3', '1-4', '0-5'];
</script>

<template>
    <div class="space-y-4">
        <!-- KPI Cards -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <KpiMatchWinRate
                :match-record="matchRecord"
                :winrate-delta="winrateDelta"
                :chart-data="chartData"
                :timeframe-label="timeframeLabel"
            />

            <Deferred data="boardingSplit">
                <template #fallback>
                    <Card class="gap-0 py-0"><CardContent class="p-4"><Skeleton class="h-28 w-full" /></CardContent></Card>
                </template>
                <KpiBoardingSplit v-if="boardingSplit" :split="boardingSplit" />
            </Deferred>

            <KpiPlayDrawGap
                :otp-rate="otpRate"
                :games-otp-won="gamesOtpWon"
                :games-otp-lost="gamesOtpLost"
                :otd-rate="otdRate"
                :games-otd-won="gamesOtdWon"
                :games-otd-lost="gamesOtdLost"
                :play-draw-games="playDrawGames"
                :total-games="totalGames"
            />

            <Deferred data="matchupSpread">
                <template #fallback>
                    <Card class="gap-0 py-0"><CardContent class="p-4"><Skeleton class="h-28 w-full" /></CardContent></Card>
                </template>
                <KpiBestWorstMatchups :spread="matchupSpread" />
            </Deferred>
        </div>

        <!-- Chart + League Finishes & Best/Worst Archetype -->
        <div class="grid grid-cols-3 gap-4">
            <Card class="col-span-2">
                <CardContent>
                    <MatchHistoryChart
                        v-if="chartData.length"
                        :data="chartData"
                        :peer="peerChart ?? null"
                        :timeframe="timeframe"
                    />
                    <p v-else class="py-12 text-center text-sm text-muted-foreground">
                        No match data for this period.
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">

                <!-- League Finishes -->
                <Deferred data="leagueResults">
                    <template #fallback>
                        <Card class="gap-0 p-0">
                            <CardContent class="flex flex-col gap-2 p-4">
                                <Skeleton class="h-6 w-full" />
                                <Skeleton class="h-6 w-full" />
                                <Skeleton class="h-6 w-3/4" />
                            </CardContent>
                        </Card>
                    </template>
                    <Card class="gap-0 overflow-hidden p-0">
                        <CardContent class="p-4">
                            <p class="mb-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">League Finishes</p>
                            <div class="flex flex-col gap-2">
                                <div v-for="bucket in leagueResultsBuckets" :key="bucket" class="flex items-center gap-3">
                                    <span class="w-8 text-right text-sm tabular-nums font-medium">{{ bucket }}</span>
                                    <div class="relative h-5 flex-1 rounded bg-muted">
                                        <div
                                            class="h-full rounded"
                                            :class="parseInt(bucket) > parseInt(bucket.split('-')[1]) ? 'bg-success' : parseInt(bucket) < parseInt(bucket.split('-')[1]) ? 'bg-destructive' : 'bg-muted-foreground'"
                                            :style="{ width: `${((activeLeagueResults[bucket] ?? 0) / leagueResultsTotal) * 100}%` }"
                                        />
                                    </div>
                                    <span class="w-6 text-right text-sm tabular-nums text-muted-foreground">{{ activeLeagueResults[bucket] ?? 0 }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </Deferred>

                                <!-- The run being played, or the last one finished -->
                <Deferred :data="['leagueInProgress', 'latestLeague']">
                    <template #fallback>
                        <Card class="gap-0 py-0"><CardContent class="p-4"><Skeleton class="h-40 w-full" /></CardContent></Card>
                    </template>
                    <LeagueRunCard v-if="currentLeagueRun" :run="currentLeagueRun" />
                </Deferred>

            </div>
        </div>

        <!-- Standout Cards -->
        <Deferred data="standoutCards">
            <template #fallback>
                <div class="grid grid-cols-6 gap-4">
                    <Card v-for="i in 6" :key="i" class="gap-0 py-0">
                        <CardContent class="p-3"><Skeleton class="h-24 w-full" /></CardContent>
                    </Card>
                </div>
            </template>
            <StandoutCards
                v-if="standoutCards"
                :top-performer="standoutCards.topPerformer"
                :most-cast="standoutCards.mostCast"
                :most-seen="standoutCards.mostSeen"
                :most-played-land="standoutCards.mostPlayedLand"
                :most-sided-in="standoutCards.mostSidedIn"
                :most-sided-out="standoutCards.mostSidedOut"
            />
        </Deferred>

    </div>
</template>
