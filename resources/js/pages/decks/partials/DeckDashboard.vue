<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Deferred } from '@inertiajs/vue3';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchHistoryChart from '@/pages/decks/partials/MatchHistoryChart.vue';
import StandoutCards from '@/pages/decks/partials/StandoutCards.vue';
import LatestLeague from '@/pages/decks/partials/LatestLeague.vue';
import type { LeagueRun } from '@/types/leagues';
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
    standoutCards?: Record<string, any>;
    latestLeague?: LeagueRun;
}>();

const MIN_MATCHES_THRESHOLD = 3;

// TODO: Remove dummy fallback data after visual review
const DUMMY_BEST = { name: 'Ruby Storm', color_identity: 'R', match_record: '4-1', match_winrate: 80, matches: 5 };
const DUMMY_WORST = { name: 'Amulet Titan', color_identity: 'U,G', match_record: '1-3', match_winrate: 25, matches: 4 };

const bestArchetype = computed(() => {
    if (!props.matchupSpread?.length) return DUMMY_BEST;
    const eligible = props.matchupSpread.filter((m: any) => m.matches >= MIN_MATCHES_THRESHOLD);
    if (!eligible.length) return DUMMY_BEST;
    return eligible.reduce((best: any, m: any) => m.match_winrate > best.match_winrate ? m : best);
});

const worstArchetype = computed(() => {
    if (!props.matchupSpread?.length) return DUMMY_WORST;
    const eligible = props.matchupSpread.filter((m: any) => m.matches >= MIN_MATCHES_THRESHOLD);
    if (!eligible.length) return DUMMY_WORST;
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
        <div class="grid grid-cols-7 gap-4">
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
            <Deferred data="matchupSpread">
                <template #fallback>
                    <Card class="gap-0 py-0"><CardContent class="p-3"><Skeleton class="h-16 w-full" /></CardContent></Card>
                </template>
                <Card class="gap-0 py-0">
                    <CardContent class="flex flex-col gap-0.5 p-3">
                        <span class="text-xs tracking-wide text-muted-foreground uppercase">Best Matchup</span>
                        <template v-if="bestArchetype">
                            <span class="text-3xl font-bold tabular-nums text-success">{{ bestArchetype.match_winrate }}%</span>
                            <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                                <ManaSymbols :symbols="bestArchetype.color_identity" class="shrink-0" />
                                <span class="truncate">{{ bestArchetype.name }}</span>
                                <span class="tabular-nums">({{ bestArchetype.match_record }})</span>
                            </span>
                        </template>
                        <span v-else class="text-sm text-muted-foreground">Not enough data</span>
                    </CardContent>
                </Card>
            </Deferred>
            <Deferred data="matchupSpread">
                <template #fallback>
                    <Card class="gap-0 py-0"><CardContent class="p-3"><Skeleton class="h-16 w-full" /></CardContent></Card>
                </template>
                <Card class="gap-0 py-0">
                    <CardContent class="flex flex-col gap-0.5 p-3">
                        <span class="text-xs tracking-wide text-muted-foreground uppercase">Worst Matchup</span>
                        <template v-if="worstArchetype">
                            <span class="text-3xl font-bold tabular-nums text-destructive">{{ worstArchetype.match_winrate }}%</span>
                            <span class="flex items-center gap-1.5 text-sm text-muted-foreground">
                                <ManaSymbols :symbols="worstArchetype.color_identity" class="shrink-0" />
                                <span class="truncate">{{ worstArchetype.name }}</span>
                                <span class="tabular-nums">({{ worstArchetype.match_record }})</span>
                            </span>
                        </template>
                        <span v-else class="text-sm text-muted-foreground">Not enough data</span>
                    </CardContent>
                </Card>
            </Deferred>
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

                <!-- Latest League -->
                <Deferred data="latestLeague">
                    <template #fallback>
                        <Card class="gap-0 p-0">
                            <CardContent class="flex flex-col gap-2 p-4">
                                <Skeleton class="h-6 w-full" />
                                <Skeleton class="h-6 w-full" />
                                <Skeleton class="h-6 w-3/4" />
                            </CardContent>
                        </Card>
                    </template>
                    <LatestLeague v-if="latestLeague" :league="latestLeague" />
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
