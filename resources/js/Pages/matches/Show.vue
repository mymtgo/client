<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import ManaSymbols from '@/components/ManaSymbols.vue';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import MatchGame from '@/Pages/matches/partials/MatchGame.vue';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { router } from '@inertiajs/vue3';
import { PencilIcon } from 'lucide-vue-next';
import dayjs from 'dayjs';

defineProps<{
    match?: App.Data.Front.MatchData;
    cards?: App.Data.Front.CardData[];
    archetypes?: App.Data.Front.ArchetypeData[];
}>();

// FAKE DATA — replace with props from backend
const fakeMatch = {
    id: 101,
    startedAt: '2026-02-17T19:34:00Z',
    matchTime: '36m',
    leagueGame: true,
    league: { id: 5, name: 'League #5' },
    deck: { id: 1, name: 'Boros Energy', format: 'Standard' },
    gamesWon: 2,
    gamesLost: 1,
    opponent: {
        username: 'Karadorinn',
        archetype: { id: 3, name: 'Dimir Midrange', colorIdentity: 'U,B' },
    },
};

const fakeGames = [
    {
        id: 201,
        number: 1,
        result: 'W',
        onThePlay: true,
        duration: '12m',
        localMulligans: 0,
        opponentMulligans: 1,
        mulliganedHands: [],
        keptHand: [
            { name: "Phlage, Titan of Fire's Fury", image: null, bottomed: false },
            { name: 'Lightning Bolt', image: null, bottomed: false },
            { name: 'Sacred Foundry', image: null, bottomed: false },
            { name: 'Mountain', image: null, bottomed: false },
            { name: 'Amped Raptor', image: null, bottomed: false },
            { name: 'Slickshot Show-Off', image: null, bottomed: false },
            { name: 'Shock', image: null, bottomed: false },
        ],
        sideboardChanges: [],
        opponentCardsSeen: [
            { name: 'Deep-Cavern Bat', image: null },
            { name: 'Preordain', image: null },
            { name: 'Duress', image: null },
            { name: 'Memory Deluge', image: null },
        ],
    },
    {
        id: 202,
        number: 2,
        result: 'L',
        onThePlay: false,
        duration: '9m',
        localMulligans: 1,
        opponentMulligans: 0,
        mulliganedHands: [
            [
                { name: 'Plains', image: null },
                { name: 'Plains', image: null },
                { name: 'Leyline of Resonance', image: null },
                { name: 'Shock', image: null },
                { name: 'Get the Point', image: null },
                { name: 'Reckless Charge', image: null },
                { name: 'Reckless Charge', image: null },
            ],
        ],
        keptHand: [
            { name: 'Sacred Foundry', image: null, bottomed: false },
            { name: 'Mountain', image: null, bottomed: false },
            { name: 'Inspiring Vantage', image: null, bottomed: false },
            { name: 'Lightning Bolt', image: null, bottomed: false },
            { name: 'Slickshot Show-Off', image: null, bottomed: false },
            { name: 'Amped Raptor', image: null, bottomed: false },
            { name: "Phlage, Titan of Fire's Fury", image: null, bottomed: true },
        ],
        sideboardChanges: [
            { name: 'Destroy Evil', quantity: 2, type: 'in' },
            { name: 'Sunfall', quantity: 1, type: 'in' },
            { name: 'Shock', quantity: 2, type: 'out' },
            { name: 'Get the Point', quantity: 1, type: 'out' },
        ],
        opponentCardsSeen: [
            { name: 'Memory Deluge', image: null },
            { name: 'Preordain', image: null },
            { name: 'Kaito Shizuki', image: null },
        ],
    },
    {
        id: 203,
        number: 3,
        result: 'W',
        onThePlay: true,
        duration: '15m',
        localMulligans: 0,
        opponentMulligans: 0,
        mulliganedHands: [],
        keptHand: [
            { name: 'Leyline of Resonance', image: null, bottomed: false },
            { name: 'Mountain', image: null, bottomed: false },
            { name: 'Sacred Foundry', image: null, bottomed: false },
            { name: 'Amped Raptor', image: null, bottomed: false },
            { name: 'Amped Raptor', image: null, bottomed: false },
            { name: "Phlage, Titan of Fire's Fury", image: null, bottomed: false },
            { name: 'Lightning Bolt', image: null, bottomed: false },
        ],
        sideboardChanges: [
            { name: 'Destroy Evil', quantity: 2, type: 'in' },
            { name: 'Sunfall', quantity: 1, type: 'in' },
            { name: 'Shock', quantity: 2, type: 'out' },
            { name: 'Get the Point', quantity: 1, type: 'out' },
        ],
        opponentCardsSeen: [
            { name: 'Deep-Cavern Bat', image: null },
            { name: 'Preordain', image: null },
            { name: 'Memory Deluge', image: null },
        ],
    },
];

const fakeArchetypes: any[] = [];

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);

const isWin = fakeMatch.gamesWon > fakeMatch.gamesLost;
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="fakeArchetypes" />

    <AppLayout
        :title="`vs ${fakeMatch.opponent.username}`"
        :breadcrumbs="[
            { label: 'Decks', href: DecksIndexController().url },
            { label: fakeMatch.deck.name, href: DeckShowController({ deck: fakeMatch.deck.id }).url },
            { label: `vs ${fakeMatch.opponent.username}` },
        ]"
    >
        <div class="flex flex-col gap-4 p-4 lg:p-6">
            <!-- Match summary -->
            <Card>
                <CardContent class="pt-5">
                    <div class="flex items-start justify-between gap-4">
                        <!-- Left: result + opponent -->
                        <div class="flex items-start gap-4">
                            <div class="mt-0.5">
                                <Badge
                                    v-if="isWin"
                                    class="border-transparent bg-win px-3 py-1 text-sm text-win-foreground"
                                >Win</Badge>
                                <Badge v-else variant="destructive" class="px-3 py-1 text-sm">Loss</Badge>
                                <p class="text-muted-foreground mt-1 text-center text-xs tabular-nums">
                                    {{ fakeMatch.gamesWon }}–{{ fakeMatch.gamesLost }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <p class="text-lg font-semibold leading-tight">vs {{ fakeMatch.opponent.username }}</p>

                                <!-- Opponent archetype -->
                                <div class="flex items-center gap-1.5">
                                    <span class="text-sm">{{ fakeMatch.opponent.archetype.name }}</span>
                                    <ManaSymbols :symbols="fakeMatch.opponent.archetype.colorIdentity" />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-5 w-5 text-muted-foreground"
                                        @click="archetypeDialog?.openForMatch(fakeMatch.id, fakeMatch.deck.format)"
                                    >
                                        <PencilIcon :size="11" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <!-- Right: metadata -->
                        <div class="text-muted-foreground flex flex-col items-end gap-1 text-sm">
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="cursor-pointer font-medium hover:underline"
                                    @click="router.visit(DeckShowController({ deck: fakeMatch.deck.id }).url)"
                                >{{ fakeMatch.deck.name }}</span>
                                <Badge variant="outline" class="text-xs">{{ fakeMatch.deck.format }}</Badge>
                            </div>
                            <p>{{ dayjs(fakeMatch.startedAt).format('MMM D, YYYY [at] h:mma') }} · {{ fakeMatch.matchTime }}</p>
                            <p v-if="fakeMatch.leagueGame">{{ fakeMatch.league.name }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Per-game sections -->
            <MatchGame
                v-for="game in fakeGames"
                :key="game.id"
                :game="game"
                :opponent-name="fakeMatch.opponent.username"
            />
        </div>
    </AppLayout>
</template>
