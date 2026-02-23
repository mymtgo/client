<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import PhantomBadge from '@/components/leagues/PhantomBadge.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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

type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string | null;
    opponentArchetype: string | null;
    games: string;
    startedAt: string;
};

type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string } | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    matches: LeagueMatch[];
};

const props = defineProps<{
    leagues: LeagueRun[];
}>();

type PhantomFilter = 'include' | 'exclude' | 'only';
const phantomFilter = ref<PhantomFilter>('include');
const phantomFilterLabel: Record<PhantomFilter, string> = {
    include: 'Include phantom',
    exclude: 'Exclude phantom',
    only:    'Only phantom',
};

const allFormats = computed(() => [...new Set(props.leagues.map((r) => r.format))].sort());
const activeFormat = ref('All');

const runWins      = (r: LeagueRun) => r.results.filter((x) => x === 'W').length;
const runLosses    = (r: LeagueRun) => r.results.filter((x) => x === 'L').length;
const isComplete   = (r: LeagueRun) => r.results.every((x) => x !== null);
const isTrophy     = (r: LeagueRun) => runWins(r) === 5 && isComplete(r) && !r.phantom;
const isInProgress = (r: LeagueRun) => !isComplete(r);

const filteredRuns = computed(() =>
    props.leagues
        .filter((r) => activeFormat.value === 'All' || r.format === activeFormat.value)
        .filter((r) => {
            if (phantomFilter.value === 'exclude') return !r.phantom;
            if (phantomFilter.value === 'only')    return r.phantom;
            return true;
        })
        .slice()
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
                        <span class="text-muted-foreground text-xs">Runs</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <div class="flex items-center gap-1.5">
                            <Trophy class="size-5 text-yellow-400" />
                            <span class="text-3xl font-bold tabular-nums">{{ kpis.trophies }}</span>
                        </div>
                        <span class="text-muted-foreground text-xs">5-0</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.fourOne }}</span>
                        <span class="text-muted-foreground text-xs">4-1</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.winRate }}%</span>
                        <span class="text-muted-foreground text-xs">Win rate</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Format pills -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <Button
                        v-for="f in ['All', ...allFormats]"
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
            <p
                v-if="filteredRuns.length === 0"
                class="text-muted-foreground py-12 text-center text-sm"
            >
                No league runs recorded yet.
            </p>

            <!-- League run cards -->
            <div v-else class="flex flex-col gap-4">
                <Card v-for="run in filteredRuns" :key="run.id" class="gap-0 overflow-hidden p-0">

                    <!-- Run header -->
                    <div class="bg-muted/40 flex items-center gap-4 border-b border-border px-4 py-3">
                        <!-- Left: date + format + deck -->
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="text-muted-foreground whitespace-nowrap text-sm">
                                {{ dayjs(run.startedAt).fromNow() }}
                            </span>
                            <Badge variant="outline" class="shrink-0">{{ run.format }}</Badge>
                            <span
                                v-if="run.deck"
                                class="text-primary cursor-pointer truncate text-sm font-medium hover:underline"
                                @click="router.visit(DeckShowController({ deck: run.deck.id }).url)"
                            >
                                {{ run.deck.name }}
                            </span>
                            <!-- Phantom badge -->
                            <PhantomBadge v-if="run.phantom" />
                        </div>

                        <!-- Right: record + pips -->
                        <div class="ml-auto flex shrink-0 items-center gap-3">
                            <!-- Record -->
                            <div class="flex items-center gap-1.5">
                                <Trophy v-if="isTrophy(run)" class="size-4 text-yellow-400" />
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
                                    class="flex size-5 shrink-0 items-center justify-center text-[10px] font-bold"
                                    :class="{
                                        'bg-success text-success-foreground':                   result === 'W',
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
                                <TableRow
                                    v-for="match in run.matches"
                                    :key="match.id"
                                    class="cursor-pointer"
                                    @click="router.visit(MatchShowController({ id: match.id }).url)"
                                >
                                    <TableCell>
                                        <ResultBadge :won="match.result === 'W'" />
                                    </TableCell>
                                    <TableCell class="font-medium">
                                        <span v-if="match.opponentName">{{ match.opponentName }}</span>
                                        <span v-else class="text-muted-foreground text-xs">—</span>
                                    </TableCell>
                                    <TableCell>
                                        <span v-if="match.opponentArchetype" class="text-sm">{{ match.opponentArchetype }}</span>
                                        <span v-else class="text-muted-foreground text-xs">Unknown</span>
                                    </TableCell>
                                    <TableCell class="tabular-nums text-sm">{{ match.games }}</TableCell>
                                    <TableCell class="text-muted-foreground whitespace-nowrap text-xs">
                                        {{ dayjs(match.startedAt).fromNow() }}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click.stop="router.visit(MatchShowController({ id: match.id }).url)"
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
