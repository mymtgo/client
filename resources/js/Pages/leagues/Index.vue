<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Empty, EmptyDescription, EmptyTitle } from '@/components/ui/empty';
import {
    DropdownMenu, DropdownMenuContent,
    DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/vue3';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import { Trophy, Ghost, ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

// FAKE DATA — replace with props from backend
type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string;
    opponentArchetype: string | null;
    games: string;
    startedAt: string;
};

type LeagueRun = {
    id: number;
    format: string;
    deck: { id: number; name: string };
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    matches: LeagueMatch[];
};

const allRuns: LeagueRun[] = [
    {
        id: 1, format: 'Standard', phantom: false,
        deck: { id: 1, name: 'Boros Energy' },
        startedAt: '2026-02-17T19:00:00Z',
        results: ['W', 'W', 'L', 'W', null],
        matches: [
            { id: 101, result: 'W', opponentName: 'Karadorinn',  opponentArchetype: 'Dimir Midrange',  games: '2-0', startedAt: '2026-02-17T19:34:00Z' },
            { id: 100, result: 'W', opponentName: 'blisterguy',  opponentArchetype: 'Azorius Oculus',  games: '2-1', startedAt: '2026-02-17T19:05:00Z' },
            { id: 99,  result: 'L', opponentName: 'Patxi_7',     opponentArchetype: 'Jeskai Control',  games: '0-2', startedAt: '2026-02-16T21:15:00Z' },
            { id: 98,  result: 'W', opponentName: 'zuberamaster', opponentArchetype: null,             games: '2-0', startedAt: '2026-02-16T20:40:00Z' },
        ],
    },
    {
        id: 2, format: 'Standard', phantom: false,
        deck: { id: 1, name: 'Boros Energy' },
        startedAt: '2026-02-10T18:00:00Z',
        results: ['W', 'W', 'W', 'W', 'W'],
        matches: [
            { id: 95, result: 'W', opponentName: 'Karadorinn',   opponentArchetype: 'Dimir Midrange',  games: '2-1', startedAt: '2026-02-10T20:10:00Z' },
            { id: 94, result: 'W', opponentName: 'HammerTime99', opponentArchetype: 'Mono-Red Aggro',  games: '2-0', startedAt: '2026-02-10T19:45:00Z' },
            { id: 93, result: 'W', opponentName: 'blisterguy',   opponentArchetype: 'Azorius Oculus',  games: '2-0', startedAt: '2026-02-10T19:20:00Z' },
            { id: 92, result: 'W', opponentName: 'Patxi_7',      opponentArchetype: 'Jeskai Control',  games: '2-1', startedAt: '2026-02-10T18:50:00Z' },
            { id: 91, result: 'W', opponentName: 'zuberamaster',  opponentArchetype: 'Domain Ramp',    games: '2-0', startedAt: '2026-02-10T18:20:00Z' },
        ],
    },
    {
        id: 3, format: 'Standard', phantom: true,
        deck: { id: 1, name: 'Boros Energy' },
        startedAt: '2026-02-14T18:00:00Z',
        results: ['W', 'L', 'W', 'W', 'L'],
        matches: [
            { id: 90, result: 'W', opponentName: 'blisterguy',   opponentArchetype: 'Azorius Oculus',  games: '2-0', startedAt: '2026-02-14T20:30:00Z' },
            { id: 89, result: 'L', opponentName: 'Karadorinn',   opponentArchetype: 'Dimir Midrange',  games: '1-2', startedAt: '2026-02-14T20:00:00Z' },
            { id: 88, result: 'W', opponentName: 'CubeSlinger',  opponentArchetype: null,              games: '2-1', startedAt: '2026-02-14T19:30:00Z' },
            { id: 87, result: 'W', opponentName: 'HammerTime99', opponentArchetype: 'Mono-Red Aggro',  games: '2-0', startedAt: '2026-02-14T19:00:00Z' },
            { id: 86, result: 'L', opponentName: 'Patxi_7',      opponentArchetype: 'Jeskai Control',  games: '0-2', startedAt: '2026-02-14T18:30:00Z' },
        ],
    },
    {
        id: 4, format: 'Modern', phantom: false,
        deck: { id: 4, name: 'Izzet Prowess' },
        startedAt: '2026-02-08T20:00:00Z',
        results: ['W', 'W', 'L', 'L', 'W'],
        matches: [
            { id: 85, result: 'W', opponentName: 'zuberamaster',  opponentArchetype: 'Burn',            games: '2-0', startedAt: '2026-02-08T22:00:00Z' },
            { id: 84, result: 'W', opponentName: 'CubeSlinger',   opponentArchetype: 'Living End',      games: '2-1', startedAt: '2026-02-08T21:30:00Z' },
            { id: 83, result: 'L', opponentName: 'HammerTime99',  opponentArchetype: 'Amulet Titan',    games: '0-2', startedAt: '2026-02-08T21:00:00Z' },
            { id: 82, result: 'L', opponentName: 'HammerTime99',  opponentArchetype: 'Coffers Control', games: '1-2', startedAt: '2026-02-08T20:30:00Z' },
            { id: 81, result: 'W', opponentName: 'blisterguy',    opponentArchetype: 'Merfolk',         games: '2-0', startedAt: '2026-02-08T20:00:00Z' },
        ],
    },
    {
        id: 5, format: 'Modern', phantom: true,
        deck: { id: 4, name: 'Izzet Prowess' },
        startedAt: '2026-02-06T20:00:00Z',
        results: ['W', 'W', 'W', 'W', 'W'],
        matches: [
            { id: 80, result: 'W', opponentName: 'Patxi_7',      opponentArchetype: 'Burn',            games: '2-1', startedAt: '2026-02-06T22:00:00Z' },
            { id: 79, result: 'W', opponentName: 'zuberamaster',  opponentArchetype: 'Merfolk',         games: '2-0', startedAt: '2026-02-06T21:30:00Z' },
            { id: 78, result: 'W', opponentName: 'CubeSlinger',   opponentArchetype: 'Living End',      games: '2-0', startedAt: '2026-02-06T21:00:00Z' },
            { id: 77, result: 'W', opponentName: 'Karadorinn',    opponentArchetype: 'Yawgmoth',        games: '2-1', startedAt: '2026-02-06T20:30:00Z' },
            { id: 76, result: 'W', opponentName: 'HammerTime99',  opponentArchetype: null,              games: '2-0', startedAt: '2026-02-06T20:00:00Z' },
        ],
    },
    {
        id: 6, format: 'Pioneer', phantom: false,
        deck: { id: 7, name: 'Rakdos Midrange' },
        startedAt: '2026-01-10T18:00:00Z',
        results: ['W', 'W', 'W', 'W', 'W'],
        matches: [
            { id: 75, result: 'W', opponentName: 'zuberamaster',  opponentArchetype: 'Mono-Green Dev',  games: '2-1', startedAt: '2026-01-10T21:00:00Z' },
            { id: 74, result: 'W', opponentName: 'blisterguy',    opponentArchetype: 'Azorius Control', games: '2-0', startedAt: '2026-01-10T20:30:00Z' },
            { id: 73, result: 'W', opponentName: 'CubeSlinger',   opponentArchetype: 'Boros Convoke',   games: '2-1', startedAt: '2026-01-10T20:00:00Z' },
            { id: 72, result: 'W', opponentName: 'Patxi_7',       opponentArchetype: null,              games: '2-0', startedAt: '2026-01-10T19:30:00Z' },
            { id: 71, result: 'W', opponentName: 'Karadorinn',    opponentArchetype: 'Lotus Field',     games: '2-1', startedAt: '2026-01-10T19:00:00Z' },
        ],
    },
];

type PhantomFilter = 'include' | 'exclude' | 'only';
const phantomFilter = ref<PhantomFilter>('include');
const phantomFilterLabel: Record<PhantomFilter, string> = {
    include: 'Include phantom',
    exclude: 'Exclude phantom',
    only:    'Only phantom',
};

const formats = [...new Set(allRuns.map((r) => r.format))];
const activeFormat = ref('All');

const runWins      = (r: LeagueRun) => r.results.filter((x) => x === 'W').length;
const runLosses    = (r: LeagueRun) => r.results.filter((x) => x === 'L').length;
const isComplete   = (r: LeagueRun) => r.results.every((x) => x !== null);
const isTrophy     = (r: LeagueRun) => runWins(r) === 5 && isComplete(r);
const isInProgress = (r: LeagueRun) => !isComplete(r);

const filteredRuns = computed(() =>
    allRuns
        .filter((r) => activeFormat.value === 'All' || r.format === activeFormat.value)
        .filter((r) => {
            if (phantomFilter.value === 'exclude') return !r.phantom;
            if (phantomFilter.value === 'only')    return r.phantom;
            return true;
        })
        .sort((a, b) => dayjs(b.startedAt).diff(dayjs(a.startedAt))),
);

const kpis = computed(() => {
    const runs   = filteredRuns.value.filter((r) => isComplete(r) && !r.phantom);
    const totalW = runs.reduce((s, r) => s + runWins(r), 0);
    const totalL = runs.reduce((s, r) => s + runLosses(r), 0);
    return {
        total:    runs.length,
        trophies: runs.filter(isTrophy).length,
        fourOne:  runs.filter((r) => runWins(r) === 4).length,
        winRate:  totalW + totalL > 0 ? Math.round((totalW / (totalW + totalL)) * 100) : 0,
    };
});
</script>

<template>
    <AppLayout title="Leagues">
        <div class="flex flex-col gap-6 p-4 lg:p-6">

            <!-- KPI bar (real leagues only) -->
            <Card>
                <CardContent class="grid grid-cols-4 divide-x divide-border p-0">
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.total }}</span>
                        <span class="text-xs text-muted-foreground">Runs</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <div class="flex items-center gap-1.5">
                            <Trophy class="size-5 text-yellow-400" />
                            <span class="text-3xl font-bold tabular-nums">{{ kpis.trophies }}</span>
                        </div>
                        <span class="text-xs text-muted-foreground">5-0</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.fourOne }}</span>
                        <span class="text-xs text-muted-foreground">4-1</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.winRate }}%</span>
                        <span class="text-xs text-muted-foreground">Win rate</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Phantom explanation -->
            <p class="text-sm text-muted-foreground -mt-2">
                <span class="inline-flex items-center gap-1 align-middle"><Ghost class="size-3.5" /></span>
                <strong class="text-foreground font-medium">Phantom leagues</strong> are your last 5 casual games grouped together — showing what your record would have been if they were a real league run. They don't count toward your stats above.
            </p>

            <!-- Toolbar -->
            <div class="flex items-center gap-2 flex-wrap">
                <!-- Format pills -->
                <div class="flex items-center gap-1.5 flex-wrap">
                    <Button
                        v-for="f in ['All', ...formats]"
                        :key="f"
                        size="sm"
                        :variant="activeFormat === f ? 'default' : 'outline'"
                        @click="activeFormat = f"
                    >
                        {{ f }}
                    </Button>
                </div>

                <!-- Phantom filter -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="ml-auto gap-1.5">
                            <Ghost class="size-3.5" />
                            {{ phantomFilterLabel[phantomFilter] }}
                            <ChevronDown class="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuRadioGroup v-model="phantomFilter">
                            <DropdownMenuRadioItem value="include">Include phantom</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="exclude">Exclude phantom</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="only">Only phantom</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- Empty state -->
            <Empty v-if="filteredRuns.length === 0">
                <EmptyTitle>No league runs</EmptyTitle>
                <EmptyDescription>League runs are detected automatically from MTGO match logs.</EmptyDescription>
            </Empty>

            <!-- League run cards -->
            <div v-else class="flex flex-col gap-4">
                <Card v-for="run in filteredRuns" :key="run.id" class="overflow-hidden gap-0 p-0">

                    <!-- Run header -->
                    <div class="flex items-center gap-4 px-4 py-3 border-b border-border bg-muted/40">
                        <!-- Left: date + format + deck -->
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-sm text-muted-foreground whitespace-nowrap">
                                {{ dayjs(run.startedAt).fromNow() }}
                            </span>
                            <Badge variant="outline" class="shrink-0">{{ run.format }}</Badge>
                            <span
                                class="font-medium text-sm cursor-pointer hover:underline text-primary truncate"
                                @click="router.visit(DeckShowController({ deck: run.deck.id }).url)"
                            >
                                {{ run.deck.name }}
                            </span>
                            <!-- Phantom badge -->
                            <Badge v-if="run.phantom" variant="outline" class="shrink-0 gap-1 text-muted-foreground">
                                <Ghost class="size-3" />
                                Phantom
                            </Badge>
                        </div>

                        <!-- Right: record + pips -->
                        <div class="ml-auto flex items-center gap-3 shrink-0">
                            <!-- Record -->
                            <div class="flex items-center gap-1.5">
                                <Trophy v-if="isTrophy(run) && !run.phantom" class="size-4 text-yellow-400" />
                                <Badge v-if="isInProgress(run)" variant="outline" class="text-muted-foreground text-xs">
                                    In progress
                                </Badge>
                                <span v-else class="text-sm font-semibold tabular-nums">
                                    {{ runWins(run) }}-{{ runLosses(run) }}
                                </span>
                            </div>

                            <!-- Pips -->
                            <div class="flex items-center gap-1">
                                <div
                                    v-for="(result, i) in run.results"
                                    :key="i"
                                    class="size-5 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0"
                                    :class="{
                                        'bg-win text-win-foreground':                          result === 'W',
                                        'bg-destructive text-destructive-foreground':          result === 'L',
                                        'bg-muted text-muted-foreground border border-border': result === null,
                                    }"
                                >
                                    <span v-if="result !== null">{{ result }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Matches table -->
                    <CardContent class="px-0 pb-0">
                        <Table>
                            <TableHeader class="bg-muted/20">
                                <TableRow>
                                    <TableHead>Result</TableHead>
                                    <TableHead>Opponent</TableHead>
                                    <TableHead>Archetype</TableHead>
                                    <TableHead>Games</TableHead>
                                    <TableHead>When</TableHead>
                                    <TableHead></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="match in run.matches" :key="match.id">
                                    <TableCell>
                                        <Badge :class="match.result === 'W'
                                            ? 'border-transparent bg-win text-win-foreground'
                                            : 'border-transparent bg-destructive text-destructive-foreground'">
                                            {{ match.result === 'W' ? 'Win' : 'Loss' }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell class="font-medium">{{ match.opponentName }}</TableCell>
                                    <TableCell>
                                        <span v-if="match.opponentArchetype" class="text-sm">{{ match.opponentArchetype }}</span>
                                        <span v-else class="text-muted-foreground text-xs">Unknown</span>
                                    </TableCell>
                                    <TableCell class="tabular-nums text-sm">{{ match.games }}</TableCell>
                                    <TableCell class="text-muted-foreground text-xs whitespace-nowrap">
                                        {{ dayjs(match.startedAt).fromNow() }}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="router.visit(MatchShowController({ id: match.id }).url)"
                                        >
                                            View
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>

                </Card>
            </div>

        </div>
    </AppLayout>
</template>
