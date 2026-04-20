<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DeckViewLayout from '@/Layouts/DeckViewLayout.vue';
import { Link } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import ShowController from '@/actions/App/Http/Controllers/Tournaments/ShowController';
import type { VersionStats } from '@/types/decks';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

type Tournament = {
    id: number;
    name: string | null;
    format: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    started_at: string | null;
};

type LocalStanding = {
    tournament_id: number;
    rank: number;
    round: number;
};

type Kpis = {
    tournaments_played: number;
    best_finish: number | null;
    top_8: number;
    top_16: number;
};

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    timeframe: string;
    tournaments: Tournament[];
    localStandings: Record<number, LocalStanding>;
    kpis: Kpis;
}>();

function stateLabel(state: string): string {
    if (state === 'completed') return 'Completed';
    return 'In Progress';
}

function stateColor(state: string): string {
    return state === 'completed' ? 'text-zinc-400' : 'text-green-500';
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- KPI Cards -->
        <div class="grid grid-cols-4 gap-4">
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Played</span>
                    <span class="text-3xl font-bold tabular-nums">{{ kpis.tournaments_played }}</span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Best Finish</span>
                    <span class="text-3xl font-bold tabular-nums">
                        <template v-if="kpis.best_finish !== null">#{{ kpis.best_finish }}</template>
                        <template v-else>-</template>
                    </span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Top 8</span>
                    <span class="text-3xl font-bold tabular-nums">{{ kpis.top_8 }}</span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="text-xs tracking-wide text-muted-foreground uppercase">Top 16</span>
                    <span class="text-3xl font-bold tabular-nums">{{ kpis.top_16 }}</span>
                </CardContent>
            </Card>
        </div>

        <div v-if="tournaments.length === 0" class="py-8 text-center text-sm text-zinc-500">
            No tournaments found for this deck. Tournaments will appear here once you participate in a tournament with this deck.
        </div>

        <Card v-if="tournaments.length > 0">
            <CardContent class="p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-800 text-left text-xs text-zinc-500">
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Format</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Round</th>
                            <th class="px-3 py-2">Your Rank</th>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tournament in tournaments"
                            :key="tournament.id"
                            class="border-b border-zinc-800/50 hover:bg-zinc-800/30"
                        >
                            <td class="px-3 py-2">{{ tournament.name || 'Tournament' }}</td>
                            <td class="px-3 py-2">{{ tournament.format || '-' }}</td>
                            <td class="px-3 py-2">
                                <span :class="stateColor(tournament.state)" class="text-xs font-medium">
                                    {{ stateLabel(tournament.state) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="tournament.current_round">
                                    {{ tournament.current_round }}<span v-if="tournament.max_rounds">/{{ tournament.max_rounds }}</span>
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="localStandings[tournament.id]">
                                    #{{ localStandings[tournament.id].rank }}
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2 text-zinc-400">
                                {{ tournament.started_at ? new Date(tournament.started_at).toLocaleDateString() : '-' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Link
                                    :href="ShowController.url({ tournament: tournament.id }) + `?from=deck&deck_id=${deck.id}`"
                                    class="text-xs text-blue-400 hover:text-blue-300"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
