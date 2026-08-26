<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import LimitedEventLayout from '@/Layouts/LimitedEventLayout.vue';
import MatchController from '@/actions/App/Http/Controllers/Limited/MatchController';
import MatchesTable from '@/components/matches/MatchesTable.vue';
import { Card, CardContent } from '@/components/ui/card';
import { TooltipProvider } from '@/components/ui/tooltip';
import { NO_VALUE, formatSeconds, timeLabel } from '@/types/limited';
import { Head } from '@inertiajs/vue3';
import { Clock, Swords, Target, Timer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: [AppLayout, LimitedEventLayout] });

type MatchesKpis = {
    wins: number;
    losses: number;
    gameWins: number;
    gameLosses: number;
    avgMatchSeconds: number | null;
    onPlay: { wins: number; losses: number };
    onDraw: { wins: number; losses: number };
    queuedAt: string | null;
    finishedAt: string | null;
    totalMinutes: number | null;
};

const props = defineProps<{
    event: App.Data.Front.LimitedEventData;
    currentPage: string;
    matches: App.Data.Front.MatchData[];
    kpis: MatchesKpis;
}>();

const DURATION_UNIT_SECONDS: Record<string, number> = { second: 1, minute: 60, hour: 3600, day: 86400, week: 604800 };

/** A win outranks a loss, and both outrank a game that was never played. */
const GAME_RESULT_RANK: Record<string, number> = { W: 2, L: 1 };

/**
 * `matchTime` is Carbon's absolute diffForHumans string ("5 minutes", "1 hour"),
 * so the Duration column has to be sorted on the elapsed time behind the label
 * rather than on the label itself. A match with no end time has no duration and
 * sorts lowest, the way SQL NULLs do in the server-side sort.
 */
function durationSeconds(matchTime: string | null): number {
    if (matchTime === null) {
        return -1;
    }

    let total = 0;
    for (const [, amount, unit] of matchTime.matchAll(/(\d+)\s*([a-z]+)/gi)) {
        total += Number(amount) * (DURATION_UNIT_SECONDS[unit.toLowerCase().replace(/s$/, '')] ?? 0);
    }

    return total;
}

/** The value each column MatchesTable can emit is ordered by. */
function sortValue(match: App.Data.Front.MatchData, column: string): number | string {
    switch (column) {
        case 'outcome':
            return match.result;
        case 'archetype':
            return match.opponentArchetypes?.[0]?.archetype?.name ?? '';
        case 'game_1':
        case 'game_2':
        case 'game_3':
            return GAME_RESULT_RANK[match.gameResults?.[Number(column.slice(-1)) - 1]?.result] ?? 0;
        case 'duration':
            return durationSeconds(match.matchTime);
        default:
            return new Date(match.startedAt).getTime();
    }
}

const sortBy = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('desc');

/**
 * The whole league is already in the payload, so sorting stays on the client.
 * Untouched headers leave the server's chronological order alone.
 */
const sortedMatches = computed<App.Data.Front.MatchData[]>(() => {
    const column = sortBy.value;
    if (column === null) {
        return props.matches;
    }

    const direction = sortDir.value === 'asc' ? 1 : -1;

    return [...props.matches].sort((a, b) => {
        const left = sortValue(a, column);
        const right = sortValue(b, column);

        if (typeof left === 'string' && typeof right === 'string') {
            return direction * left.localeCompare(right);
        }

        return direction * (Number(left) - Number(right));
    });
});

/** Descending, then ascending, then back to the server's own order. */
function updateSort(column: string): void {
    if (sortBy.value !== column) {
        sortBy.value = column;
        sortDir.value = 'desc';

        return;
    }

    if (sortDir.value === 'desc') {
        sortDir.value = 'asc';

        return;
    }

    sortBy.value = null;
    sortDir.value = 'desc';
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <Head :title="`${event.title} · Matches`" />

        <div class="flex flex-col">
            <h1 class="text-base font-semibold tracking-tight">Matches</h1>
            <p class="text-sm text-muted-foreground">
                {{ matches.length }} league match{{ matches.length === 1 ? '' : 'es' }}
                <template v-if="kpis.queuedAt">
                    · queued {{ timeLabel(kpis.queuedAt) }} · finished {{ timeLabel(kpis.finishedAt) }} · {{ kpis.totalMinutes }} min total</template
                >
            </p>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase"
                        ><Target class="size-3" /> Record</span
                    >
                    <span class="text-3xl font-bold tabular-nums">{{ kpis.wins }}-{{ kpis.losses }}</span>
                    <span class="text-sm text-muted-foreground">games {{ kpis.gameWins }}-{{ kpis.gameLosses }}</span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase"
                        ><Timer class="size-3" /> Avg match</span
                    >
                    <span class="text-3xl font-bold tabular-nums">{{
                        kpis.avgMatchSeconds !== null ? Math.round(kpis.avgMatchSeconds / 60) + 'm' : NO_VALUE
                    }}</span>
                    <span class="text-sm text-muted-foreground">{{ formatSeconds(kpis.avgMatchSeconds) }}</span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase"
                        ><Swords class="size-3" /> On the play</span
                    >
                    <span class="text-3xl font-bold tabular-nums">{{ kpis.onPlay.wins }}-{{ kpis.onPlay.losses }}</span>
                    <span class="text-sm text-muted-foreground">on the draw {{ kpis.onDraw.wins }}-{{ kpis.onDraw.losses }}</span>
                </CardContent>
            </Card>
            <Card class="gap-0 py-0">
                <CardContent class="flex flex-col gap-0.5 p-3">
                    <span class="inline-flex items-center gap-1 text-xs tracking-wide text-muted-foreground uppercase"
                        ><Clock class="size-3" /> Total time</span
                    >
                    <span class="text-3xl font-bold tabular-nums">{{ kpis.totalMinutes !== null ? kpis.totalMinutes + 'm' : NO_VALUE }}</span>
                    <span class="text-sm text-muted-foreground">first queue to last result</span>
                </CardContent>
            </Card>
        </div>

        <div v-if="!matches.length" class="flex flex-col items-center gap-2 py-16 text-center">
            <Swords class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No league matches yet.</p>
            <p class="text-sm text-muted-foreground">Matches attach here as soon as they finish.</p>
        </div>
        <TooltipProvider v-else>
            <MatchesTable
                :matches="sortedMatches"
                :show-deck="false"
                :sort-by="sortBy"
                :sort-dir="sortDir"
                :match-url="(id) => MatchController.url({ league: event.id, match: id })"
                @sort="updateSort"
            />
        </TooltipProvider>
    </div>
</template>
