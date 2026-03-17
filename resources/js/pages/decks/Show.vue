<script setup lang="ts">
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Deferred, router } from '@inertiajs/vue3';
import OpenPopoutController from '@/actions/App/Http/Controllers/Decks/OpenPopoutController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import DeckDashboard from '@/pages/decks/partials/DeckDashboard.vue';
import DeckCardStats from '@/pages/decks/partials/DeckCardStats.vue';
import DeckMatches from '@/pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/pages/decks/partials/DeckLeagues.vue';
import DeckMatchups from '@/pages/decks/partials/DeckMatchups.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
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
    // Deferred props
    matches?: any;
    leagues?: any[];
    archetypes?: App.Data.Front.ArchetypeData[];
    matchupSpread?: any[];
    leagueResults?: Record<string, number>;
    cardStats?: any[];
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
        <div class="p-3 lg:p-4">
            <!-- Deck header -->
            <div class="flex items-center justify-between">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight">{{ deck.name }}</h1>
                        <ManaSymbols v-if="deck.colorIdentity" :symbols="deck.colorIdentity" />
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

            <Tabs default-value="dashboard" class="mt-8">
                <TabsList>
                    <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
                    <TabsTrigger value="card-stats">Card Stats</TabsTrigger>
                    <TabsTrigger value="matches">Matches ({{ matchesWon + matchesLost }})</TabsTrigger>
                    <TabsTrigger value="leagues">Leagues{{ leagues ? ` (${leagues.length})` : '' }}</TabsTrigger>
                    <TabsTrigger value="matchups">Matchups</TabsTrigger>
                    <TabsTrigger value="decklist">Decklist</TabsTrigger>
                </TabsList>

                <TabsContent value="dashboard">
                    <DeckDashboard
                        :active-version="activeVersion"
                        :chart-data="chartData"
                        :matchup-spread="matchupSpread"
                        :league-results="leagueResults"
                    />
                </TabsContent>

                <TabsContent value="card-stats">
                    <DeckCardStats :card-stats="cardStats" />
                </TabsContent>

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
                        <DeckMatchups :matchup-spread="matchupSpread!" />
                    </Deferred>
                </TabsContent>

                <TabsContent value="decklist">
                    <DeckList :maindeck="activeDecklist.maindeck" :sideboard="activeDecklist.sideboard" />
                </TabsContent>
            </Tabs>
        </div>
    </div>
</template>
