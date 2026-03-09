<script setup lang="ts">
import { computed, ref } from 'vue';
import BackLink from '@/components/BackLink.vue';
import { Button } from '@/components/ui/button';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import MatchGame from '@/pages/matches/partials/MatchGame.vue';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
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

    <div class="flex flex-col gap-4 p-3 lg:p-4">
            <!-- Back link -->
            <BackLink
                v-if="deck"
                :href="DeckShowController({ deck: deck.id }).url"
                :label="deck.name"
            />

            <!-- Match header -->
            <div class="flex flex-col gap-1">
                <!-- Result + opponent -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <ResultBadge :won="isWin" :showText="true" />
                        <span class="text-sm font-semibold tabular-nums">
                            {{ match.gamesWon }}-{{ match.gamesLost }}
                        </span>
                    </div>
                    <div class="h-4 w-px bg-border" />
                    <h1 class="text-sm font-semibold">vs {{ match.opponentName ?? 'Unknown' }}</h1>
                </div>

                <!-- Archetype -->
                <div class="flex items-center gap-1.5">
                    <template v-if="opponentArchetype?.archetype">
                        <span class="text-sm">{{ opponentArchetype.archetype.name }}</span>
                        <ManaSymbols :symbols="opponentArchetype.archetype.colorIdentity" />
                    </template>
                    <span v-else class="text-sm text-muted-foreground">Unknown archetype</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-5 w-5 text-muted-foreground"
                        @click="archetypeDialog?.openForMatch(match.id, match.format)"
                    >
                        <PencilIcon :size="11" />
                    </Button>
                </div>

                <!-- Metadata line -->
                <p class="text-sm text-muted-foreground">
                    <template v-if="deck">{{ deck.name }} · </template>
                    {{ match.format }}
                    · {{ dayjs(match.startedAt).format('MMM D, YYYY [at] h:mma') }}
                    · {{ match.matchTime }}
                    <template v-if="match.leagueName"> · {{ match.leagueName }}</template>
                </p>
            </div>

            <!-- Per-game sections -->
            <div class="flex flex-col gap-3">
                <MatchGame
                    v-for="game in games"
                    :key="game.id"
                    :game="game"
                    :opponent-name="(match.opponentName as string) ?? 'Opponent'"
                />
            </div>
    </div>
</template>
