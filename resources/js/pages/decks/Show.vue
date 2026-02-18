<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/AppLayout.vue';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
    matches: any;
    leagues: App.Data.Front.LeagueData[];
    archetypes: App.Data.Front.ArchetypeData[];
    matchupSpread: any[];
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
    versionDecklists: Record<string, VersionDecklist>;
    chartData: { date: string; winrate: string }[];
}>();

const selectedVersionKey = ref<string>('all');

const activeVersion = computed((): VersionStats => {
    if (selectedVersionKey.value === 'all') return props.versions[0];
    const id = parseInt(selectedVersionKey.value);
    return props.versions.find((v) => v.id === id) ?? props.versions[0];
});

// "All versions" shows the latest version's decklist
const latestVersionId = computed(() => props.versions[props.versions.length - 1]?.id);

const activeDecklist = computed((): VersionDecklist => {
    const key = selectedVersionKey.value === 'all'
        ? String(latestVersionId.value)
        : selectedVersionKey.value;
    return props.versionDecklists[key] ?? { maindeck: props.maindeck, sideboard: props.sideboard };
});

// All-time stats for the header (from deck DTO)
const allTime = computed(() => props.versions[0]);
</script>

<template>
    <AppLayout
        :title="deck.name"
        :breadcrumbs="[{ label: 'Decks', href: DecksIndexController().url }, { label: deck.name }]"
    >
        <div class="grid grow grid-cols-12 items-start">
            <!-- Main content -->
            <div class="col-span-8 flex flex-col gap-4 p-4 lg:p-6">
                <!-- Deck header -->
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight">{{ deck.name }}</h1>
                        <Badge variant="outline">{{ deck.format }}</Badge>
                        <span
                            class="text-lg font-semibold"
                            :class="allTime.matchWinrate < 50 ? 'text-destructive' : ''"
                        >{{ allTime.matchWinrate }}%</span>
                        <span class="text-muted-foreground text-sm">
                            {{ allTime.matchesWon + allTime.matchesLost }} matches
                        </span>
                    </div>
                    <p class="text-muted-foreground text-sm">
                        Last played {{ deck.lastPlayedAt ? dayjs(deck.lastPlayedAt).fromNow() : 'never' }}
                    </p>
                </div>

                <!-- Stats row (updates per selected version) -->
                <Card>
                    <CardContent class="flex divide-x p-0">
                        <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                            <span class="text-muted-foreground text-xs uppercase tracking-wide">Match W/L</span>
                            <span class="text-lg font-semibold tabular-nums">
                                {{ activeVersion.matchesWon }}–{{ activeVersion.matchesLost }}
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                            <span class="text-muted-foreground text-xs uppercase tracking-wide">Game W/L</span>
                            <span class="text-lg font-semibold tabular-nums">
                                {{ activeVersion.gamesWon }}–{{ activeVersion.gamesLost }}
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                            <span class="text-muted-foreground text-xs uppercase tracking-wide">On the Play</span>
                            <span class="text-lg font-semibold tabular-nums">
                                {{ activeVersion.otpRate }}%
                                <span class="text-muted-foreground text-xs font-normal">
                                    {{ activeVersion.gamesOtpWon }}–{{ activeVersion.gamesOtpLost }}
                                </span>
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                            <span class="text-muted-foreground text-xs uppercase tracking-wide">On the Draw</span>
                            <span class="text-lg font-semibold tabular-nums">
                                {{ activeVersion.otdRate }}%
                                <span class="text-muted-foreground text-xs font-normal">
                                    {{ activeVersion.gamesOtdWon }}–{{ activeVersion.gamesOtdLost }}
                                </span>
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Win rate chart -->
                <Card v-if="chartData.length">
                    <CardContent class="pt-4">
                        <MatchHistoryChart :data="chartData" />
                    </CardContent>
                </Card>

                <!-- Tabs -->
                <Tabs default-value="matches">
                    <TabsList>
                        <TabsTrigger value="matches">Matches</TabsTrigger>
                        <TabsTrigger value="matchups">Matchups</TabsTrigger>
                        <TabsTrigger value="leagues">Leagues</TabsTrigger>
                    </TabsList>
                    <TabsContent value="matches">
                        <DeckMatches :matches="matches" :archetypes="archetypes" />
                    </TabsContent>
                    <TabsContent value="matchups">
                        <MatchupSpread :matchup-spread="matchupSpread" />
                    </TabsContent>
                    <TabsContent value="leagues">
                        <DeckLeagues :leagues="leagues" :archetypes="archetypes" />
                    </TabsContent>
                </Tabs>
            </div>

            <!-- Sticky decklist sidebar -->
            <div class="no-scrollbar col-span-4 sticky top-0  overflow-y-auto border-l p-4 lg:p-6">
                <div class="mb-4">
                    <Select v-model="selectedVersionKey">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All versions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All versions</SelectItem>
                            <SelectItem
                                v-for="version in versions.slice(1)"
                                :key="version.id"
                                :value="String(version.id)"
                            >
                                {{ version.label }}
                                <span v-if="version.isCurrent" class="text-muted-foreground ml-1">· Current</span>
                                <span v-if="version.dateLabel" class="text-muted-foreground ml-1">· {{ version.dateLabel }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <DeckList :maindeck="activeDecklist.maindeck" :sideboard="activeDecklist.sideboard" />
            </div>
        </div>
    </AppLayout>
</template>
