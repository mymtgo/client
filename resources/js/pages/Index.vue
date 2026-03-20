<script setup lang="ts">
import { Deferred, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { LayoutDashboard } from 'lucide-vue-next';
import DashboardKpiStrip from '@/pages/partials/DashboardKpiStrip.vue';
import DashboardFormatChart from '@/pages/partials/DashboardFormatChart.vue';
import DashboardDecks from '@/pages/partials/DashboardDecks.vue';
import DashboardSessionRecap from '@/pages/partials/DashboardSessionRecap.vue';
import DashboardMatchupSpread from '@/pages/partials/DashboardMatchupSpread.vue';
import DashboardRollingForm from '@/pages/partials/DashboardRollingForm.vue';
import DashboardLeagueResults from '@/pages/partials/DashboardLeagueResults.vue';

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

type Streak = {
    current: string | null;
    bestWin: number;
    bestLoss: number;
};

type PlayDrawSplit = {
    otpWinrate: number;
    otdWinrate: number;
};

type SessionMatch = {
    id: number;
    outcome: string;
    opponentArchetype: string;
    gamesWon: number;
    gamesLost: number;
};

type LastSession = {
    startedAt: string;
    endedAt: string;
    matches: SessionMatch[];
    record: string;
    duration: string;
};

type MatchupEntry = {
    name: string;
    winrate: number;
    wins: number;
    losses: number;
    matches: number;
};

type RollingForm = {
    results: string[];
    winrate: number;
    allTimeWinrate: number;
    delta: number;
};

type LeagueDistribution = {
    buckets: Record<string, number>;
    trophies: number;
    total: number;
};

const props = defineProps<{
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    deckStats: App.Data.Front.DeckData[];
    timeframe: string;
    activeLeague: ActiveLeague | null;
    formatChart: FormatChart;
    streak: Streak;
    matchWinrateDelta: number;
    gameWinrateDelta: number;
    playDrawSplit: PlayDrawSplit;
    // Deferred props
    lastSession?: LastSession | null;
    matchupSpread?: MatchupEntry[];
    rollingForm?: RollingForm;
    leagueDistribution?: LeagueDistribution;
}>();

const timeframes = [
    { value: 'week', label: '7 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'monthly', label: '30 days' },
    { value: 'year', label: 'This year' },
    { value: 'alltime', label: 'All time' },
];

const hasData = computed(() => props.matchesWon + props.matchesLost > 0);

function setTimeframe(value: string) {
    router.get('/', { timeframe: value }, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- KPI Strip -->
        <DashboardKpiStrip
            :streak="streak"
            :match-winrate="matchWinrate"
            :match-winrate-delta="matchWinrateDelta"
            :game-winrate="gameWinrate"
            :game-winrate-delta="gameWinrateDelta"
            :play-draw-split="playDrawSplit"
            :active-league="activeLeague"
            :matches-won="matchesWon"
            :matches-lost="matchesLost"
            :games-won="gamesWon"
            :games-lost="gamesLost"
        />

        <!-- Timeframe selector -->
        <div class="flex items-center gap-1 self-start rounded-md border p-1">
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

        <!-- Empty state -->
        <div v-if="!hasData" class="flex flex-col items-center gap-2 py-16 text-center">
            <LayoutDashboard class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No match data yet</p>
            <p class="text-sm text-muted-foreground">Start the file watcher in Settings to begin tracking your MTGO matches.</p>
        </div>

        <template v-else>
            <!-- Row 1: Format Chart + Session Recap -->
            <div class="grid grid-cols-5 gap-4">
                <div class="col-span-3">
                    <DashboardFormatChart :format-chart="formatChart" />
                </div>
                <div class="col-span-2">
                    <Deferred :data="['lastSession']">
                        <template #fallback>
                            <div class="h-full animate-pulse rounded-xl bg-muted" />
                        </template>
                        <DashboardSessionRecap :last-session="lastSession ?? null" />
                    </Deferred>
                </div>
            </div>

            <!-- Row 2: Matchup Spread + Rolling Form -->
            <div class="grid grid-cols-2 gap-4">
                <Deferred :data="['matchupSpread']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardMatchupSpread :matchup-spread="matchupSpread ?? []" />
                </Deferred>
                <Deferred :data="['rollingForm']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardRollingForm
                        :rolling-form="rollingForm ?? { results: [], winrate: 0, allTimeWinrate: 0, delta: 0 }"
                    />
                </Deferred>
            </div>

            <!-- Row 3: Deck Performance + League Results -->
            <div class="grid grid-cols-2 gap-4">
                <DashboardDecks :deck-stats="deckStats" />
                <Deferred :data="['leagueDistribution']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardLeagueResults
                        :league-distribution="leagueDistribution ?? { buckets: {}, trophies: 0, total: 0 }"
                    />
                </Deferred>
            </div>
        </template>
    </div>
</template>
