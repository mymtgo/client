<script setup lang="ts">
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Deferred } from '@inertiajs/vue3';
import DeckMatches from '@/pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/pages/decks/partials/DeckLeagues.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchHistoryChart from '@/pages/decks/partials/MatchHistoryChart.vue';
import { Download, Trophy } from 'lucide-vue-next';
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
    leagues?: App.Data.Front.LeagueData[];
    archetypes?: App.Data.Front.ArchetypeData[];
    matchupSpread?: any[];
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
                                    {{ activeVersion.matchesWon }}–{{ activeVersion.matchesLost }}
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
                                    {{ activeVersion.gamesWon }}–{{ activeVersion.gamesLost }}
                                </span>
                            </CardContent>
                        </Card>
                        <Card class="gap-0 py-0">
                            <CardContent class="flex flex-col gap-0.5 p-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Match Record</span>
                                <span class="text-3xl font-bold tabular-nums">
                                    {{ activeVersion.matchesWon }}–{{ activeVersion.matchesLost }}
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
                                    {{ activeVersion.gamesOtpWon }}–{{ activeVersion.gamesOtpLost }} games
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
                                    {{ activeVersion.gamesOtdWon }}–{{ activeVersion.gamesOtdLost }} games
                                </span>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Chart + Matchup Spread -->
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

                        <div>
                            <Deferred data="matchupSpread">
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
                                    <CardContent class="max-h-[460px] overflow-y-auto px-0 scrollbar-none">
                                        <p v-if="!matchupSpread?.length" class="text-muted-foreground py-8 text-center text-sm">
                                            No matchup data yet.
                                        </p>
                                        <table v-else class="w-full text-sm">
                                            <thead class="bg-muted sticky top-0">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-muted-foreground">Archetype</th>
                                                    <th class="px-3 py-2 text-right text-xs font-medium text-muted-foreground">Record</th>
                                                    <th class="px-3 py-2 text-right text-xs font-medium text-muted-foreground">Win %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="matchup in matchupSpread" :key="matchup.archetype_id" class="border-b border-border">
                                                    <td class="px-3 py-2">
                                                        <div class="flex items-center gap-1.5">
                                                            <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                                                            <span class="truncate">{{ matchup.name }}</span>
                                                        </div>
                                                    </td>
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
                        </div>
                    </div>

                    <!-- Tabs -->
                    <Tabs default-value="matches">
                        <TabsList>
                            <TabsTrigger value="matches">Matches</TabsTrigger>
                            <TabsTrigger value="leagues">Leagues</TabsTrigger>
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
                            <Deferred :data="['leagues', 'archetypes']">
                                <template #fallback>
                                    <Card class="gap-0 overflow-hidden p-0">
                                        <CardContent class="flex flex-col gap-2 px-4 py-4">
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-full" />
                                            <Skeleton class="h-8 w-2/3" />
                                        </CardContent>
                                    </Card>
                                </template>
                                <DeckLeagues :leagues="leagues!" :archetypes="archetypes!" />
                            </Deferred>
                        </TabsContent>
                    </Tabs>
                    </TabsContent>
                </Tabs>
            </div>
    </div>
</template>
