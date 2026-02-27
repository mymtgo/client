<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/AppLayout.vue';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import DecksShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Deferred, router } from '@inertiajs/vue3';
import MatchupSpread from '@/pages/decks/partials/MatchupSpread.vue';
import DeckMatches from '@/pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/pages/decks/partials/DeckLeagues.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import MatchHistoryChart from '@/pages/decks/partials/MatchHistoryChart.vue';
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
    period: string;
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
    chartData: { date: string; winrate: string | null }[];
    chartGranularity: 'daily' | 'monthly';
    // Deferred props — undefined until background request resolves
    matches?: any;
    leagues?: App.Data.Front.LeagueData[];
    archetypes?: App.Data.Front.ArchetypeData[];
    matchupSpread?: any[];
    versionDecklists?: Record<string, VersionDecklist>;
}>();

const currentVersion = computed(() => props.versions.find((v) => v.isCurrent));
const selectedVersionKey = ref<string>(currentVersion.value?.id ? String(currentVersion.value.id) : 'all');

type Period = 'all_time' | 'this_year' | 'this_month' | 'this_week';
const periodLabels: Record<Period, string> = {
    all_time: 'All time',
    this_year: 'This year',
    this_month: 'This month',
    this_week: 'This week',
};
const activePeriod = ref<Period>((props.period as Period) ?? 'all_time');

const onPeriodChange = (value: string) => {
    const options = value === 'all_time' ? {} : { query: { period: value } };
    router.visit(DecksShowController({ deck: props.deck.id }, options).url, {
        preserveScroll: true,
    });
};

const activeVersion = computed((): VersionStats => {
    if (selectedVersionKey.value === 'all') return props.versions[0];
    const id = parseInt(selectedVersionKey.value);
    return props.versions.find((v) => v.id === id) ?? props.versions[0];
});

// "All versions" shows the latest version's decklist
const latestVersionId = computed(() => props.versions[props.versions.length - 1]?.id);

const activeDecklist = computed((): VersionDecklist => {
    const key = selectedVersionKey.value === 'all' ? String(latestVersionId.value) : selectedVersionKey.value;
    return props.versionDecklists?.[key] ?? { maindeck: props.maindeck, sideboard: props.sideboard };
});

// All-time stats for the header (from deck DTO)
const allTime = computed(() => props.versions[0]);
</script>

<template>
    <AppLayout :title="deck.name" :breadcrumbs="[{ label: 'Decks', href: DecksIndexController().url }, { label: deck.name }]">
        <div>
            <!-- Main content -->
            <div class="p-3 lg:p-4">
                <!-- Deck header -->
                <div class="flex items-center justify-between">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-bold tracking-tight">{{ deck.name }}</h1>
                            <Badge variant="outline">{{ deck.format }}</Badge>
                            <span class="text-lg font-semibold" :class="allTime.matchWinrate < 50 ? 'text-destructive' : ''"
                                >{{ allTime.matchWinrate }}%</span
                            >
                            <span class="text-sm text-muted-foreground"> {{ allTime.matchesWon + allTime.matchesLost }} matches </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Last played {{ deck.lastPlayedAt ? dayjs(deck.lastPlayedAt).fromNow() : 'never' }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Select v-model="activePeriod" @update:model-value="onPeriodChange">
                            <SelectTrigger class="h-7 w-32 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="(label, key) in periodLabels" :key="key" :value="key" class="text-xs">
                                    {{ label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Select v-model="selectedVersionKey">
                            <SelectTrigger>
                                <SelectValue placeholder="All versions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All versions</SelectItem>
                                <SelectItem v-for="version in versions.slice(1)" :key="version.id" :value="String(version.id)">
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
                    <!-- Stats row (updates per selected version) -->
                    <Card>
                        <CardContent class="flex divide-x p-0">
                            <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Match W/L</span>
                                <span class="text-lg font-semibold tabular-nums">
                                    {{ activeVersion.matchesWon }}–{{ activeVersion.matchesLost }}
                                </span>
                            </div>
                            <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">Game W/L</span>
                                <span class="text-lg font-semibold tabular-nums"> {{ activeVersion.gamesWon }}–{{ activeVersion.gamesLost }} </span>
                            </div>
                            <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">On the Play</span>
                                <span class="text-lg font-semibold tabular-nums">
                                    {{ activeVersion.otpRate }}%
                                    <span class="text-xs font-normal text-muted-foreground">
                                        {{ activeVersion.gamesOtpWon }}–{{ activeVersion.gamesOtpLost }}
                                    </span>
                                </span>
                            </div>
                            <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                                <span class="text-xs tracking-wide text-muted-foreground uppercase">On the Draw</span>
                                <span class="text-lg font-semibold tabular-nums">
                                    {{ activeVersion.otdRate }}%
                                    <span class="text-xs font-normal text-muted-foreground">
                                        {{ activeVersion.gamesOtdWon }}–{{ activeVersion.gamesOtdLost }}
                                    </span>
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Win rate chart -->
                    <Card v-if="chartData.length">
                        <CardContent>
                            <MatchHistoryChart :data="chartData" :granularity="chartGranularity" />
                        </CardContent>
                    </Card>

                    <!-- No chart but still show period selector -->
                    <div v-else class="flex justify-end">
                        <Select v-model="activePeriod" @update:model-value="onPeriodChange">
                            <SelectTrigger class="h-7 w-32 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="(label, key) in periodLabels" :key="key" :value="key" class="text-xs">
                                    {{ label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Tabs -->
                    <Tabs default-value="matches">
                        <TabsList>
                            <TabsTrigger value="matches">Matches</TabsTrigger>
                            <TabsTrigger value="matchups">Matchups</TabsTrigger>
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
                                <MatchupSpread :matchup-spread="matchupSpread!" />
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
    </AppLayout>
</template>
