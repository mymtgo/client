<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import MatchupSpread from '@/Pages/decks/partials/MatchupSpread.vue';
import DeckMatches from '@/Pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/Pages/decks/partials/DeckLeagues.vue';
import DeckList from '@/Pages/decks/partials/DeckList.vue';
import MatchHistoryChart from '@/Pages/decks/partials/MatchHistoryChart.vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

defineProps<{
    deck?: App.Data.Front.DeckData;
    maindeck?: App.Data.Front.CardData[];
    sideboard?: App.Data.Front.CardData[];
    matches?: App.Data.Front.MatchData[];
    leagues?: App.Data.Front.LeagueData[];
    archetypes?: App.Data.Front.ArchetypeData[];
    matchupSpread?: any[];
    matchesWon?: number;
    matchesLost?: number;
}>();

// FAKE DATA — replace with props from backend

const fakeDeck = { id: 1, name: 'Boros Energy', format: 'Standard', lastPlayedAt: '2026-02-17T19:34:00Z' };

// Version list — index 0 is always "All versions" (all-time aggregate)
const versionList = [
    {
        id: null,
        label: 'All versions',
        dateLabel: null,
        isCurrent: false,
        changes: null,
        matchesWon: 23, matchesLost: 11,
        gamesWon: 52, gamesLost: 28,
        matchWinrate: 68, gameWinrate: 65,
        gamesOtpWon: 28, gamesOtpLost: 12, otpRate: 70,
        gamesOtdWon: 24, gamesOtdLost: 16, otdRate: 60,
    },
    {
        id: 3,
        label: 'v3',
        dateLabel: 'Jan 28 – now',
        isCurrent: true,
        changes: '+2 Amped Raptor, −2 Shock',
        matchesWon: 11, matchesLost: 4,
        gamesWon: 24, gamesLost: 10,
        matchWinrate: 73, gameWinrate: 71,
        gamesOtpWon: 13, gamesOtpLost: 4, otpRate: 76,
        gamesOtdWon: 11, gamesOtdLost: 6, otdRate: 65,
    },
    {
        id: 2,
        label: 'v2',
        dateLabel: 'Jan 10 – Jan 27',
        isCurrent: false,
        changes: '+1 Sacred Foundry, −1 Mountain',
        matchesWon: 8, matchesLost: 4,
        gamesWon: 18, gamesLost: 10,
        matchWinrate: 67, gameWinrate: 64,
        gamesOtpWon: 10, gamesOtpLost: 4, otpRate: 71,
        gamesOtdWon: 8, gamesOtdLost: 6, otdRate: 57,
    },
    {
        id: 1,
        label: 'v1',
        dateLabel: 'Dec 01 – Jan 09',
        isCurrent: false,
        changes: 'Initial build',
        matchesWon: 4, matchesLost: 3,
        gamesWon: 10, gamesLost: 8,
        matchWinrate: 57, gameWinrate: 56,
        gamesOtpWon: 5, gamesOtpLost: 4, otpRate: 56,
        gamesOtdWon: 5, gamesOtdLost: 4, otdRate: 56,
    },
];

// Per-version decklists — keyed by version id
const versionDecks: Record<number, { maindeck: object; sideboard: any[] }> = {
    3: {
        maindeck: {
            Creature: [
                { id: 1, name: "Phlage, Titan of Fire's Fury", quantity: 4, identity: 'R,W', image: null },
                { id: 2, name: 'Amped Raptor', quantity: 4, identity: 'R', image: null },
                { id: 3, name: 'Slickshot Show-Off', quantity: 4, identity: 'R', image: null },
            ],
            Instant: [
                { id: 4, name: 'Lightning Bolt', quantity: 4, identity: 'R', image: null },
                { id: 5, name: 'Shock', quantity: 2, identity: 'R', image: null },
                { id: 6, name: 'Get the Point', quantity: 2, identity: 'R,W', image: null },
                { id: 7, name: 'Reckless Charge', quantity: 4, identity: 'R', image: null },
            ],
            Enchantment: [
                { id: 8, name: 'Leyline of Resonance', quantity: 4, identity: 'R', image: null },
            ],
            Land: [
                { id: 9, name: 'Sacred Foundry', quantity: 4, identity: 'R,W', image: null },
                { id: 10, name: 'Inspiring Vantage', quantity: 4, identity: 'R,W', image: null },
                { id: 11, name: 'Mountain', quantity: 10, identity: 'R', image: null },
                { id: 12, name: 'Plains', quantity: 4, identity: 'W', image: null },
            ],
        },
        sideboard: [
            { id: 13, name: 'Destroy Evil', quantity: 3, identity: 'W', image: null },
            { id: 14, name: 'Leyline of Sanctity', quantity: 2, identity: 'W', image: null },
            { id: 15, name: 'Sunfall', quantity: 2, identity: 'W', image: null },
            { id: 16, name: 'Mystical Dispute', quantity: 3, identity: 'U', image: null },
            { id: 17, name: 'Lithomantic Barrage', quantity: 2, identity: 'R', image: null },
            { id: 18, name: 'Torch the Tower', quantity: 3, identity: 'R', image: null },
        ],
    },
    2: {
        maindeck: {
            Creature: [
                { id: 1, name: "Phlage, Titan of Fire's Fury", quantity: 4, identity: 'R,W', image: null },
                { id: 2, name: 'Amped Raptor', quantity: 2, identity: 'R', image: null },
                { id: 3, name: 'Slickshot Show-Off', quantity: 4, identity: 'R', image: null },
            ],
            Instant: [
                { id: 4, name: 'Lightning Bolt', quantity: 4, identity: 'R', image: null },
                { id: 5, name: 'Shock', quantity: 4, identity: 'R', image: null },
                { id: 6, name: 'Get the Point', quantity: 2, identity: 'R,W', image: null },
                { id: 7, name: 'Reckless Charge', quantity: 4, identity: 'R', image: null },
            ],
            Enchantment: [
                { id: 8, name: 'Leyline of Resonance', quantity: 4, identity: 'R', image: null },
            ],
            Land: [
                { id: 9, name: 'Sacred Foundry', quantity: 4, identity: 'R,W', image: null },
                { id: 10, name: 'Inspiring Vantage', quantity: 4, identity: 'R,W', image: null },
                { id: 11, name: 'Mountain', quantity: 11, identity: 'R', image: null },
                { id: 12, name: 'Plains', quantity: 4, identity: 'W', image: null },
            ],
        },
        sideboard: [
            { id: 13, name: 'Destroy Evil', quantity: 3, identity: 'W', image: null },
            { id: 14, name: 'Leyline of Sanctity', quantity: 2, identity: 'W', image: null },
            { id: 15, name: 'Sunfall', quantity: 2, identity: 'W', image: null },
            { id: 16, name: 'Mystical Dispute', quantity: 3, identity: 'U', image: null },
            { id: 17, name: 'Lithomantic Barrage', quantity: 2, identity: 'R', image: null },
            { id: 18, name: 'Torch the Tower', quantity: 3, identity: 'R', image: null },
        ],
    },
    1: {
        maindeck: {
            Creature: [
                { id: 1, name: "Phlage, Titan of Fire's Fury", quantity: 4, identity: 'R,W', image: null },
                { id: 2, name: 'Amped Raptor', quantity: 2, identity: 'R', image: null },
                { id: 3, name: 'Slickshot Show-Off', quantity: 4, identity: 'R', image: null },
            ],
            Instant: [
                { id: 4, name: 'Lightning Bolt', quantity: 4, identity: 'R', image: null },
                { id: 5, name: 'Shock', quantity: 4, identity: 'R', image: null },
                { id: 6, name: 'Get the Point', quantity: 2, identity: 'R,W', image: null },
                { id: 7, name: 'Reckless Charge', quantity: 4, identity: 'R', image: null },
            ],
            Enchantment: [
                { id: 8, name: 'Leyline of Resonance', quantity: 4, identity: 'R', image: null },
            ],
            Land: [
                { id: 9, name: 'Sacred Foundry', quantity: 3, identity: 'R,W', image: null },
                { id: 10, name: 'Inspiring Vantage', quantity: 4, identity: 'R,W', image: null },
                { id: 11, name: 'Mountain', quantity: 12, identity: 'R', image: null },
                { id: 12, name: 'Plains', quantity: 4, identity: 'W', image: null },
            ],
        },
        sideboard: [
            { id: 13, name: 'Destroy Evil', quantity: 3, identity: 'W', image: null },
            { id: 14, name: 'Leyline of Sanctity', quantity: 2, identity: 'W', image: null },
            { id: 15, name: 'Sunfall', quantity: 4, identity: 'W', image: null },
            { id: 16, name: 'Mystical Dispute', quantity: 3, identity: 'U', image: null },
            { id: 17, name: 'Torch the Tower', quantity: 3, identity: 'R', image: null },
        ],
    },
};

const chartData = [
    { date: '2025-09-01', winrate: '55' },
    { date: '2025-10-01', winrate: '61' },
    { date: '2025-11-01', winrate: '58' },
    { date: '2025-12-01', winrate: '64' },
    { date: '2026-01-01', winrate: '67' },
    { date: '2026-02-01', winrate: '68' },
];

const fakeMatches = {
    total: 5,
    per_page: 50,
    data: [
        { id: 101, gamesWon: 2, gamesLost: 0, leagueGame: true, matchTime: '14m', startedAt: '2026-02-17T19:34:00Z', opponentArchetypes: [{ archetype: { name: 'Dimir Midrange', colorIdentity: 'U,B' } }] },
        { id: 100, gamesWon: 1, gamesLost: 2, leagueGame: true, matchTime: '22m', startedAt: '2026-02-17T19:05:00Z', opponentArchetypes: [{ archetype: { name: 'Azorius Oculus', colorIdentity: 'W,U' } }] },
        { id: 99, gamesWon: 2, gamesLost: 1, leagueGame: true, matchTime: '31m', startedAt: '2026-02-16T21:15:00Z', opponentArchetypes: [] },
        { id: 98, gamesWon: 2, gamesLost: 0, leagueGame: true, matchTime: '18m', startedAt: '2026-02-15T14:22:00Z', opponentArchetypes: [{ archetype: { name: 'Dimir Midrange', colorIdentity: 'U,B' } }] },
        { id: 97, gamesWon: 0, gamesLost: 2, leagueGame: true, matchTime: '11m', startedAt: '2026-02-14T21:30:00Z', opponentArchetypes: [{ archetype: { name: 'Temur Oculus', colorIdentity: 'G,U' } }] },
    ],
};

const fakeMatchupSpread = [
    { name: 'Dimir Midrange', color_identity: 'U,B', match_record: '8-3', match_winrate: 73, game_record: '18-9', game_winrate: 67 },
    { name: 'Azorius Oculus', color_identity: 'W,U', match_record: '3-4', match_winrate: 43, game_record: '8-10', game_winrate: 44 },
    { name: 'Temur Oculus', color_identity: 'G,U', match_record: '4-2', match_winrate: 67, game_record: '10-6', game_winrate: 63 },
    { name: 'Mono-Red Aggro', color_identity: 'R', match_record: '5-1', match_winrate: 83, game_record: '11-4', game_winrate: 73 },
    { name: 'Esper Midrange', color_identity: 'W,U', match_record: '2-2', match_winrate: 50, game_record: '5-5', game_winrate: 50 },
];

const fakeLeagues = [
    {
        name: 'League #5',
        matches: [
            { id: 101, gamesWon: 2, gamesLost: 0, leagueGame: true, matchTime: '14m', startedAt: '2026-02-17T19:34:00Z', opponentArchetypes: [{ archetype: { name: 'Dimir Midrange', colorIdentity: 'U,B' } }] },
            { id: 100, gamesWon: 1, gamesLost: 2, leagueGame: true, matchTime: '22m', startedAt: '2026-02-17T19:05:00Z', opponentArchetypes: [] },
            { id: 99, gamesWon: 2, gamesLost: 1, leagueGame: true, matchTime: '31m', startedAt: '2026-02-16T21:15:00Z', opponentArchetypes: [] },
            { id: 98, gamesWon: 2, gamesLost: 0, leagueGame: true, matchTime: '18m', startedAt: '2026-02-15T14:22:00Z', opponentArchetypes: [{ archetype: { name: 'Dimir Midrange', colorIdentity: 'U,B' } }] },
            { id: 97, gamesWon: 0, gamesLost: 2, leagueGame: true, matchTime: '11m', startedAt: '2026-02-14T21:30:00Z', opponentArchetypes: [{ archetype: { name: 'Temur Oculus', colorIdentity: 'G,U' } }] },
        ],
    },
    {
        name: 'League #4',
        matches: [
            { id: 90, gamesWon: 2, gamesLost: 1, leagueGame: true, matchTime: '28m', startedAt: '2026-02-08T18:00:00Z', opponentArchetypes: [{ archetype: { name: 'Mono-Red Aggro', colorIdentity: 'R' } }] },
            { id: 89, gamesWon: 2, gamesLost: 0, leagueGame: true, matchTime: '13m', startedAt: '2026-02-08T17:30:00Z', opponentArchetypes: [] },
            { id: 88, gamesWon: 0, gamesLost: 2, leagueGame: true, matchTime: '19m', startedAt: '2026-02-07T21:10:00Z', opponentArchetypes: [{ archetype: { name: 'Azorius Oculus', colorIdentity: 'W,U' } }] },
            { id: 87, gamesWon: 2, gamesLost: 1, leagueGame: true, matchTime: '34m', startedAt: '2026-02-06T19:45:00Z', opponentArchetypes: [] },
            { id: 86, gamesWon: 2, gamesLost: 0, leagueGame: true, matchTime: '12m', startedAt: '2026-02-06T19:00:00Z', opponentArchetypes: [{ archetype: { name: 'Dimir Midrange', colorIdentity: 'U,B' } }] },
        ],
    },
];

const fakeArchetypes: any[] = [];

// Version state — 'all' maps to the All versions aggregate (index 0)
const selectedVersionKey = ref<string>('all');

const activeVersion = computed(() => {
    if (selectedVersionKey.value === 'all') return versionList[0];
    const id = parseInt(selectedVersionKey.value);
    return versionList.find((v) => v.id === id) ?? versionList[0];
});

const activeDecklist = computed(() => {
    if (selectedVersionKey.value === 'all') return versionDecks[3]; // latest
    return versionDecks[parseInt(selectedVersionKey.value)];
});

// All-time stats (always shown in header, regardless of selected version)
const allTime = versionList[0];
</script>

<template>
    <AppLayout :title="fakeDeck.name">
        <div class="grid grow grid-cols-12 items-start">
            <!-- Main content -->
            <div class="col-span-8 flex flex-col gap-4 p-4 lg:p-6">
                <!-- Deck header (all-time stats) -->
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight">{{ fakeDeck.name }}</h1>
                        <Badge variant="outline">{{ fakeDeck.format }}</Badge>
                        <span
                            class="text-lg font-semibold"
                            :class="allTime.matchWinrate >= 50 ? 'text-win' : 'text-destructive'"
                        >{{ allTime.matchWinrate }}%</span>
                        <span class="text-muted-foreground text-sm">{{ allTime.matchesWon + allTime.matchesLost }} matches</span>
                    </div>
                    <p class="text-muted-foreground text-sm">
                        Last played {{ dayjs(fakeDeck.lastPlayedAt).fromNow() }}
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
                                <span class="text-muted-foreground text-xs font-normal">{{ activeVersion.gamesOtpWon }}–{{ activeVersion.gamesOtpLost }}</span>
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col gap-0.5 px-4 py-3">
                            <span class="text-muted-foreground text-xs uppercase tracking-wide">On the Draw</span>
                            <span class="text-lg font-semibold tabular-nums">
                                {{ activeVersion.otdRate }}%
                                <span class="text-muted-foreground text-xs font-normal">{{ activeVersion.gamesOtdWon }}–{{ activeVersion.gamesOtdLost }}</span>
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Win rate chart -->
                <Card>
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
                        <DeckMatches :matches="fakeMatches" :archetypes="fakeArchetypes" />
                    </TabsContent>
                    <TabsContent value="matchups">
                        <MatchupSpread :matchupSpread="fakeMatchupSpread" />
                    </TabsContent>
                    <TabsContent value="leagues">
                        <DeckLeagues :leagues="fakeLeagues" :archetypes="fakeArchetypes" />
                    </TabsContent>
                </Tabs>
            </div>

            <!-- Sticky decklist sidebar -->
            <div class="no-scrollbar col-span-4 sticky top-0 max-h-screen overflow-y-auto border-l p-4 lg:p-6">
                <div class="mb-4">
                    <Select v-model="selectedVersionKey">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All versions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All versions</SelectItem>
                            <SelectItem
                                v-for="version in versionList.slice(1)"
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
