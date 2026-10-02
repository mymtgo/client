<script setup lang="ts">
import GameStatsController from '@/actions/App/Http/Controllers/Decks/GameStatsController';
import AppLayout from '@/AppLayout.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import DeckViewLayout from '@/layouts/DeckViewLayout.vue';
import GameStatsTable from '@/pages/decks/partials/GameStatsTable.vue';
import type { VersionStats } from '@/types/decks';
import { router } from '@inertiajs/vue3';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

type StatRow = {
    group: 'all_games' | 'game_1' | 'game_2' | 'game_3';
    split: 'overall' | 'play' | 'draw';
    wins: number;
    losses: number;
    win_rate: number | null;
    mulligans: number | null;
    opponent_mulligans: number | null;
    turns: number | null;
    duration: number | null;
    clock_left: number | null;
    opponent_clock_left: number | null;
    clock_games: number;
};

type OpponentOption = {
    uuid: string;
    name: string;
    color_identity: string | null;
};

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    opponent: string | null;
    stats: {
        rows: StatRow[];
        opponents: OpponentOption[];
    };
}>();

const ALL_OPPONENTS = '__all__';

function navigate(nextTimeframe: string, nextOpponent: string | null) {
    const query: Record<string, string> = {};
    if (nextTimeframe !== 'alltime') query.timeframe = nextTimeframe;
    if (nextOpponent) query.opponent = nextOpponent;
    router.get(GameStatsController.url({ deck: props.deck.id }), query, { preserveScroll: true });
}

function setTimeframe(value: string) {
    navigate(value, props.opponent);
}

function setOpponent(value: string) {
    navigate(props.timeframe, value === ALL_OPPONENTS ? null : value);
}

const hasGames = props.stats.rows.some((r) => r.wins + r.losses > 0);

/** Clock data comes from the MTGO helper only, so say how much of the record it covers. */
const gameRows = props.stats.rows.filter((r) => r.group !== 'all_games' && r.split === 'overall');
const clockGames = gameRows.reduce((sum, r) => sum + r.clock_games, 0);
const totalGames = gameRows.reduce((sum, r) => sum + r.wins + r.losses, 0);
</script>

<template>
    <div class="space-y-4 p-3 lg:p-4">
        <div class="flex flex-wrap items-center gap-3">
            <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />

            <Select v-if="stats.opponents.length > 0" :model-value="opponent ?? ALL_OPPONENTS" @update:model-value="setOpponent">
                <SelectTrigger class="h-9 w-56 text-sm">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL_OPPONENTS">All Opponents</SelectItem>
                    <SelectItem v-for="opp in stats.opponents" :key="opp.uuid" :value="opp.uuid">
                        {{ opp.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div v-if="!hasGames" class="rounded-md border border-dashed border-black/40 bg-muted/20 p-8 text-center text-sm text-muted-foreground">
            No games yet — play some matches with this deck to see stats here.
        </div>

        <template v-else>
            <GameStatsTable :rows="stats.rows" />
            <p class="text-xs text-muted-foreground">
                Clock left is the average time remaining at the end of each game, and at the end of the match for All Games.
                <template v-if="clockGames < totalGames">
                    It covers {{ clockGames }} of {{ totalGames }} games: the clock is only recorded while the MTGO helper is running.
                </template>
            </p>
        </template>
    </div>
</template>
