<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Deferred, router } from '@inertiajs/vue3';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchHistoryChart from '@/pages/decks/partials/MatchHistoryChart.vue';
import DashboardController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import { computed } from 'vue';

const props = defineProps<{
    matchesWon: number;
    matchesLost: number;
    matchWinrate: number;
    gamesWon: number;
    gamesLost: number;
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
    deckId: number;
    timeframe: string;
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
    router.get(DashboardController.url({ deck: props.deckId }), query, { preserveScroll: true });
}

const MIN_MATCHES_THRESHOLD = 3;

const bestArchetype = computed(() => {
    if (!props.matchupSpread?.length) return null;
    const eligible = props.matchupSpread.filter((m: any) => m.matches >= MIN_MATCHES_THRESHOLD);
    if (!eligible.length) return null;
    return eligible.reduce((best: any, m: any) => m.match_winrate > best.match_winrate ? m : best);
});

const worstArchetype = computed(() => {
    if (!props.matchupSpread?.length) return null;
    const eligible = props.matchupSpread.filter((m: any) => m.matches >= MIN_MATCHES_THRESHOLD);
    if (!eligible.length) return null;
    return eligible.reduce((worst: any, m: any) => m.match_winrate < worst.match_winrate ? m : worst);
});

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
        <div class="grid grid-cols-5 gap-4">
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Match Win Rate</span>
                    <span
                        class="text-3xl font-bold tabular-nums"
                        :class="matchWinrate > 50 ? 'text-success' : matchWinrate < 50 ? 'text-destructive' : ''"
                    >{{ matchWinrate }}%</span>
                    <span class="text-sm text-muted-foreground">
                        {{ matchesWon }}-{{ matchesLost }}
                    </span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Game Win Rate</span>
                    <span
                        class="text-3xl font-bold tabular-nums"
                        :class="gameWinrate > 50 ? 'text-success' : gameWinrate < 50 ? 'text-destructive' : ''"
                    >{{ gameWinrate }}%</span>
                    <span class="text-sm text-muted-foreground">
                        {{ gamesWon }}-{{ gamesLost }}
                    </span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Match Record</span>
                    <span class="text-3xl font-bold tabular-nums">
                        {{ matchesWon }}-{{ matchesLost }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ matchesWon + matchesLost }} played
                    </span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Win % on the Play</span>
                    <span
                        class="text-3xl font-bold tabular-nums"
                        :class="otpRate > 50 ? 'text-success' : otpRate < 50 ? 'text-destructive' : ''"
                    >{{ otpRate }}%</span>
                    <span class="text-sm text-muted-foreground">
                        {{ gamesOtpWon }}-{{ gamesOtpLost }} games
                    </span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Win % on the Draw</span>
                    <span
                        class="text-3xl font-bold tabular-nums"
                        :class="otdRate > 50 ? 'text-success' : otdRate < 50 ? 'text-destructive' : ''"
                    >{{ otdRate }}%</span>
                    <span class="text-sm text-muted-foreground">
                        {{ gamesOtdWon }}-{{ gamesOtdLost }} games
                    </span>
                </CardContent>
            </Card>
        </div>

        <!-- Chart + League Finishes & Best/Worst Archetype -->
        <div class="grid grid-cols-3 gap-4">
            <Card class="col-span-2">
                <CardContent>
                    <MatchHistoryChart
                        v-if="chartData.length"
                        :data="chartData"
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

                <!-- Best/Worst Archetype -->
                <Deferred data="matchupSpread">
                    <template #fallback>
                        <div class="grid grid-cols-2 gap-4">
                            <Card class="gap-0 py-0"><CardContent class="p-3"><Skeleton class="h-12 w-full" /></CardContent></Card>
                            <Card class="gap-0 py-0"><CardContent class="p-3"><Skeleton class="h-12 w-full" /></CardContent></Card>
                        </div>
                    </template>
                    <div class="grid grid-cols-2 gap-4">
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Best Matchup</span>
                                <template v-if="bestArchetype">
                                    <div class="flex items-center gap-2">
                                        <ManaSymbols :symbols="bestArchetype.color_identity" class="shrink-0" />
                                        <span class="truncate font-medium">{{ bestArchetype.name }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm tabular-nums text-muted-foreground">{{ bestArchetype.match_record }}</span>
                                        <span class="text-sm font-medium tabular-nums text-success">{{ bestArchetype.match_winrate }}%</span>
                                    </div>
                                </template>
                                <span v-else class="text-sm text-muted-foreground">Not enough data</span>
                            </CardContent>
                        </Card>
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Worst Matchup</span>
                                <template v-if="worstArchetype">
                                    <div class="flex items-center gap-2">
                                        <ManaSymbols :symbols="worstArchetype.color_identity" class="shrink-0" />
                                        <span class="truncate font-medium">{{ worstArchetype.name }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm tabular-nums text-muted-foreground">{{ worstArchetype.match_record }}</span>
                                        <span class="text-sm font-medium tabular-nums text-destructive">{{ worstArchetype.match_winrate }}%</span>
                                    </div>
                                </template>
                                <span v-else class="text-sm text-muted-foreground">Not enough data</span>
                            </CardContent>
                        </Card>
                    </div>
                </Deferred>
            </div>
        </div>

    </div>
</template>
