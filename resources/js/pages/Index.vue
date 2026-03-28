<script setup lang="ts">
import { Deferred, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import { LayoutDashboard } from 'lucide-vue-next';
import DashboardKpiStrip from '@/pages/partials/DashboardKpiStrip.vue';
import DashboardDecks from '@/pages/partials/DashboardDecks.vue';
import DashboardSessionRecap from '@/pages/partials/DashboardSessionRecap.vue';
import DashboardMatchupSpread from '@/pages/partials/DashboardMatchupSpread.vue';
import DashboardRollingForm from '@/pages/partials/DashboardRollingForm.vue';
import DashboardLeagueResults from '@/pages/partials/DashboardLeagueResults.vue';
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
    format: string | null;
    formats: { value: string; label: string }[];
    activeLeague: ActiveLeague | null;
    streak: Streak;
    matchWinrateDelta: number;
    gameWinrateDelta: number;
    playDrawSplit: PlayDrawSplit;
    // Deferred props
    lastSession?: LastSession | null;
    matchupSpread?: MatchupEntry[];
    rollingForm?: RollingForm;
    leagueDistribution?: LeagueDistribution;
    recentMatches?: App.Data.Front.MatchData[];
}>();

const hasData = computed(() => props.matchesWon + props.matchesLost > 0);

function navigate(params: Record<string, string | null>) {
    const query: Record<string, string> = { timeframe: props.timeframe };
    if (props.format) query.format = props.format;
    Object.assign(query, params);
    // Remove null values
    Object.keys(query).forEach((k) => {
        if (query[k] === null || query[k] === undefined) delete query[k];
    });
    router.get('/', query, { preserveScroll: true });
}

function setTimeframe(value: string) {
    navigate({ timeframe: value });
}

function setFormat(value: string) {
    navigate({ format: value === 'all' ? null : value });
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

        <!-- Timeframe + Format selector -->
        <div class="flex items-center gap-3">
            <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />

            <Select :modelValue="format ?? 'all'" @update:modelValue="setFormat" v-if="formats.length > 1">
                <SelectTrigger class="h-9 w-40">
                    <SelectValue placeholder="All formats" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All formats</SelectItem>
                    <SelectItem v-for="f in formats" :key="f.value" :value="f.value">
                        {{ f.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Empty state -->
        <div v-if="!hasData" class="flex flex-col items-center gap-2 py-16 text-center">
            <LayoutDashboard class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No match data yet</p>
            <p class="text-sm text-muted-foreground">Start the file watcher in Settings to begin tracking your MTGO matches.</p>
        </div>

        <template v-else>
            <!-- Row 1: League Finishes + Rolling Form + Last Session -->
            <div class="grid grid-cols-3 gap-4">
                <Deferred :data="['leagueDistribution']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardLeagueResults
                        :league-distribution="leagueDistribution ?? { buckets: {}, trophies: 0, total: 0 }"
                    />
                </Deferred>
                <Deferred :data="['rollingForm']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardRollingForm
                        :rolling-form="rollingForm ?? { results: [], winrate: 0, allTimeWinrate: 0, delta: 0 }"
                    />
                </Deferred>
                <Deferred :data="['lastSession']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardSessionRecap :last-session="lastSession ?? null" />
                </Deferred>
            </div>

            <!-- Row 2: Deck Performance + Matchup Spread -->
            <div class="grid grid-cols-2 gap-4">
                <DashboardDecks :deck-stats="deckStats" />
                <Deferred :data="['matchupSpread']">
                    <template #fallback>
                        <div class="h-48 animate-pulse rounded-xl bg-muted" />
                    </template>
                    <DashboardMatchupSpread :matchup-spread="matchupSpread ?? []" />
                </Deferred>
            </div>

            <!-- Row 3: Recent Matches -->
            <Deferred :data="['recentMatches']">
                <template #fallback>
                    <div class="h-48 animate-pulse rounded-xl bg-muted" />
                </template>
                <DashboardRecentMatches :matches="recentMatches ?? []" />
            </Deferred>
        </template>
    </div>
</template>
