<script setup lang="ts">
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Deferred, router } from '@inertiajs/vue3';
import OpenPopoutController from '@/actions/App/Http/Controllers/Decks/OpenPopoutController';
import DeckMatches from '@/pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/pages/decks/partials/DeckLeagues.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchHistoryChart from '@/pages/decks/partials/MatchHistoryChart.vue';
import { Download, ExternalLink, Trophy } from 'lucide-vue-next';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

type VersionStats = {
    id: number | null;
    label: string;
    isCurrent: boolean;
    dateLabel: string | null;
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
};

type VersionDecklist = {
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
};

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    trophies: number;
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
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
    versions: VersionStats[];
    chartData: { date: string; wins: number; losses: number; winrate: string | null }[];
    // Deferred props — undefined until background request resolves
    matches?: any;
    leagues?: any[];
    archetypes?: App.Data.Front.ArchetypeData[];
    matchupSpread?: any[];
    leagueResults?: Record<string, number>;
    versionDecklists?: Record<string, VersionDecklist>;
}>();

const realVersions = computed(() => props.versions.slice(1));
const currentVersion = computed(() => realVersions.value.find((v) => v.isCurrent) ?? realVersions.value[realVersions.value.length - 1]);
const selectedVersionKey = ref<string>(String(currentVersion.value?.id ?? ''));

const activeVersion = computed((): VersionStats => {
    const id = parseInt(selectedVersionKey.value);
    return realVersions.value.find((v) => v.id === id) ?? currentVersion.value;
});

const activeDecklist = computed((): VersionDecklist => {
    return props.versionDecklists?.[selectedVersionKey.value] ?? { maindeck: props.maindeck, sideboard: props.sideboard };
});

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

const decklistOrgUrl = computed(() => {
    const dl = activeDecklist.value;
    const mainCards = Object.values(dl.maindeck).flat().map((c) => `${c.quantity} ${c.name}`).join('\n');
    const sideCards = dl.sideboard.map((c) => `${c.quantity} ${c.name}`).join('\n');
    const params = new URLSearchParams({
        deckmain: mainCards,
        deckside: sideCards,
        eventformat: props.deck.format,
    });
    return `https://decklist.org/?${params.toString()}`;
});

</script>

<template>
    <div>
            <!-- Main content -->
            <div class="p-3 lg:p-4">
                <!-- Deck header -->
                <div class="flex items-center justify-between">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-bold tracking-tight">{{ deck.name }}</h1>
                            <Badge variant="outline">{{ deck.format }}</Badge>
                            <span v-if="trophies" class="flex items-center gap-1 text-sm font-medium">
                                <Trophy class="size-4 text-yellow-400" />
                                {{ trophies }}
                            </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Last played {{ deck.lastPlayedAt ? dayjs(deck.lastPlayedAt).fromNow() : 'never' }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            @click="router.post(OpenPopoutController.url({ deck: deck.id }))"
                            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ExternalLink class="size-4" />
                            Popout Deck
                        </button>
                        <a :href="decklistOrgUrl" target="_blank" class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground">
                            <Download class="size-4" />
                            Deck Registration
                        </a>
                        <Select v-if="realVersions.length > 1" v-model="selectedVersionKey">
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="version in realVersions" :key="version.id" :value="String(version.id)">
                                    {{ version.label }}
                                    <span v-if="version.isCurrent" class="ml-1 text-muted-foreground">· Current</span>
                                    <span v-if="version.dateLabel" class="ml-1 text-muted-foreground">· {{ version.dateLabel }}</span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <Tabs default-value="stats" class="mt-8">
                    <TabsList>
                        <TabsTrigger value="stats">Stats</TabsTrigger>
                        <TabsTrigger value="decklist">Decklist</TabsTrigger>
                    </TabsList>

                    <TabsContent value="decklist">
                        <DeckList :maindeck="activeDecklist.maindeck" :sideboard="activeDecklist.sideboard" />
                    </TabsContent>

                    <TabsContent value="stats" class="space-y-4">
                    <p class="text-xs text-muted-foreground">Stats from the last 2 months</p>

                    <!-- KPI Cards -->
                    <div class="grid grid-cols-5 gap-4">
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Match Win Rate</span>
                                <span
                                    class="text-3xl font-bold tabular-nums"
                                    :class="activeVersion.matchWinrate > 50 ? 'text-success' : activeVersion.matchWinrate < 50 ? 'text-destructive' : ''"
                                >{{ activeVersion.matchWinrate }}%</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ activeVersion.matchesWon }}-{{ activeVersion.matchesLost }}
                                </span>
                            </CardContent>
                        </Card>
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Game Win Rate</span>
                                <span
                                    class="text-3xl font-bold tabular-nums"
                                    :class="activeVersion.gameWinrate > 50 ? 'text-success' : activeVersion.gameWinrate < 50 ? 'text-destructive' : ''"
                                >{{ activeVersion.gameWinrate }}%</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ activeVersion.gamesWon }}-{{ activeVersion.gamesLost }}
                                </span>
                            </CardContent>
                        </Card>
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Match Record</span>
                                <span class="text-3xl font-bold tabular-nums">
                                    {{ activeVersion.matchesWon }}-{{ activeVersion.matchesLost }}
                                </span>
                                <span class="text-sm text-muted-foreground">
                                    {{ activeVersion.matchesWon + activeVersion.matchesLost }} played
                                </span>
                            </CardContent>
                        </Card>
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Win % on the Play</span>
                                <span
                                    class="text-3xl font-bold tabular-nums"
                                    :class="activeVersion.otpRate > 50 ? 'text-success' : activeVersion.otpRate < 50 ? 'text-destructive' : ''"
                                >{{ activeVersion.otpRate }}%</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ activeVersion.gamesOtpWon }}-{{ activeVersion.gamesOtpLost }} games
                                </span>
                            </CardContent>
                        </Card>
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Win % on the Draw</span>
                                <span
                                    class="text-3xl font-bold tabular-nums"
                                    :class="activeVersion.otdRate > 50 ? 'text-success' : activeVersion.otdRate < 50 ? 'text-destructive' : ''"
                                >{{ activeVersion.otdRate }}%</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ activeVersion.gamesOtdWon }}-{{ activeVersion.gamesOtdLost }} games
                                </span>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Chart + League Results & Best/Worst Archetype -->
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
                            <!-- League Results -->
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
                                        <p class="mb-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">League Results</p>
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

                    <!-- Tabs -->
                    <Tabs default-value="matches">
                        <TabsList>
                            <TabsTrigger value="matches">Matches ({{ matchesWon + matchesLost }})</TabsTrigger>
                            <TabsTrigger value="leagues">Leagues{{ leagues ? ` (${leagues.length})` : '' }}</TabsTrigger>
                            <TabsTrigger value="matchups">Matchups</TabsTrigger>
                        </TabsList>
                        <TabsContent value="matches">
                            <Deferred :data="['matches', 'archetypes']">
                                <template #fallback>
                                    <Card class="gap-0 overflow-hidden p-0">
                                        <CardContent class="flex flex-col gap-2 px-4 py-4">
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-4/5" />
                                        </CardContent>
                                    </Card>
                                </template>
                                <DeckMatches :matches="matches!" :archetypes="archetypes!" />
                            </Deferred>
                        </TabsContent>
                        <TabsContent value="leagues">
                            <Deferred data="leagues">
                                <template #fallback>
                                    <Card class="gap-0 overflow-hidden p-0">
                                        <CardContent class="flex flex-col gap-2 px-4 py-4">
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-2/3" />
                                        </CardContent>
                                    </Card>
                                </template>
                                <DeckLeagues :leagues="leagues!" />
                            </Deferred>
                        </TabsContent>
                        <TabsContent value="matchups">
                            <Deferred data="matchupSpread">
                                <template #fallback>
                                    <Card class="gap-0 overflow-hidden p-0">
                                        <CardContent class="flex flex-col gap-2 px-4 py-4">
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-3/4" />
                                        </CardContent>
                                    </Card>
                                </template>
                                <Card class="gap-0 overflow-hidden p-0">
                                    <CardContent class="px-0">
                                        <p v-if="!matchupSpread?.length" class="py-8 text-center text-sm text-muted-foreground">
                                            No matchup data yet.
                                        </p>
                                        <table v-else class="w-full text-sm">
                                            <thead class="bg-muted sticky top-0">
                                                <tr>
                                                    <th class="w-8 py-2 pl-3 pr-0 text-left text-xs font-medium text-muted-foreground"></th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-muted-foreground">Archetype</th>
                                                    <th class="px-3 py-2 text-right text-xs font-medium text-muted-foreground">Record</th>
                                                    <th class="px-3 py-2 text-right text-xs font-medium text-muted-foreground">Win %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="matchup in matchupSpread" :key="matchup.archetype_id" class="border-b border-border">
                                                    <td class="w-8 py-2 pl-3 pr-0">
                                                        <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                                                    </td>
                                                    <td class="truncate px-3 py-2">{{ matchup.name }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ matchup.match_record }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <span
                                                            class="font-medium tabular-nums"
                                                            :class="matchup.match_winrate > 50 ? 'text-success' : matchup.match_winrate < 50 ? 'text-destructive' : ''"
                                                        >{{ matchup.match_winrate }}%</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </CardContent>
                                </Card>
                            </Deferred>
                        </TabsContent>
                    </Tabs>
                    </TabsContent>
                </Tabs>
            </div>
    </div>
</template>
