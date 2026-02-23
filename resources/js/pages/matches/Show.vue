<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Card, CardContent } from '@/components/ui/card';
import ManaSymbols from '@/components/ManaSymbols.vue';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import MatchGame from '@/pages/matches/partials/MatchGame.vue';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { router } from '@inertiajs/vue3';
import { PencilIcon } from 'lucide-vue-next';
import dayjs from 'dayjs';

type GameDetail = {
    id: number;
    number: number;
    won: boolean;
    onThePlay: boolean;
    duration: string | null;
    turns: number | null;
    localMulligans: number;
    opponentMulligans: number;
    mulliganedHands: { name: string; image: string | null }[][];
    keptHand: { name: string; image: string | null; bottomed: boolean }[];
    sideboardChanges: { name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
    localCardsPlayed: { name: string; image: string | null }[];
    opponentCardsSeen: { name: string; image: string | null }[];
};

const props = defineProps<{
    match: App.Data.Front.MatchData;
    games: GameDetail[];
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);

const isWin = computed(() => props.match.gamesWon > props.match.gamesLost);
const deck = computed(() => props.match.deck as App.Data.Front.DeckData | null);
const opponentArchetype = computed(() => {
    const archetypes = props.match.opponentArchetypes as App.Data.Front.MatchArchetypeData[] | null;
    return archetypes?.[0] ?? null;
});
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes" />

    <AppLayout
        :title="`vs ${match.opponentName ?? 'Unknown'}`"
        :breadcrumbs="[
            { label: 'Decks', href: DecksIndexController().url },
            { label: deck?.name ?? '—', href: deck ? DeckShowController({ deck: deck.id }).url : undefined },
            { label: `vs ${match.opponentName ?? 'Unknown'}` },
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
                                <ResultBadge :won="isWin" />
                                <p class="text-muted-foreground mt-1 text-center text-xs tabular-nums">
                                    {{ match.gamesWon }}–{{ match.gamesLost }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <p class="text-lg font-semibold leading-tight">
                                    vs {{ match.opponentName ?? 'Unknown' }}
                                </p>

                                <!-- Opponent archetype -->
                                <div class="flex items-center gap-1.5">
                                    <template v-if="opponentArchetype?.archetype">
                                        <span class="text-sm">{{ opponentArchetype.archetype.name }}</span>
                                        <ManaSymbols :symbols="opponentArchetype.archetype.colorIdentity" />
                                    </template>
                                    <span v-else class="text-muted-foreground text-sm">Unknown archetype</span>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-5 w-5 text-muted-foreground"
                                        @click="archetypeDialog?.openForMatch(match.id, match.format)"
                                    >
                                        <PencilIcon :size="11" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <!-- Right: metadata -->
                        <div class="text-muted-foreground flex flex-col items-end gap-1 text-sm">
                            <div v-if="deck" class="flex items-center gap-1.5">
                                <span
                                    class="cursor-pointer font-medium hover:underline"
                                    @click="router.visit(DeckShowController({ deck: deck.id }).url)"
                                >{{ deck.name }}</span>
                                <Badge variant="outline" class="text-xs">{{ match.format }}</Badge>
                            </div>
                            <p>{{ dayjs(match.startedAt).format('MMM D, YYYY [at] h:mma') }} · {{ match.matchTime }}</p>
                            <p v-if="match.leagueName">{{ match.leagueName }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Per-game sections -->
            <MatchGame
                v-for="game in games"
                :key="game.id"
                :game="game"
                :opponent-name="(match.opponentName as string) ?? 'Opponent'"
            />
        </div>
    </AppLayout>
</template>
