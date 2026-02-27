<script lang="ts" setup>
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import { Trophy } from 'lucide-vue-next';
import ResultBadge from '../matches/ResultBadge.vue';
import { Badge } from '../ui/badge';
import { Card, CardContent } from '../ui/card';
import PhantomBadge from './PhantomBadge.vue';

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

defineProps<{
    league: LeagueRun;
}>();

const runWins = (r: LeagueRun) => r.results.filter((x) => x === 'W').length;
const runLosses = (r: LeagueRun) => r.results.filter((x) => x === 'L').length;
const isComplete = (r: LeagueRun) => r.results.every((x) => x !== null);
const isInProgress = (r: LeagueRun) => !isComplete(r);
const isTrophy = (r: LeagueRun) => runWins(r) === 5 && isComplete(r) && !r.phantom;
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <!-- Run header -->
        <div class="flex items-center gap-4 border-b border-border bg-muted/40 px-4 py-3">
            <!-- Left: date + format + deck -->
            <div class="flex min-w-0 items-center gap-2">
                <span class="text-sm whitespace-nowrap text-muted-foreground">
                    {{ dayjs(league.startedAt).fromNow() }}
                </span>
                <Badge variant="outline" class="shrink-0">{{ league.format }}</Badge>
                <span
                    v-if="league.deck"
                    class="cursor-pointer truncate text-sm font-medium text-primary hover:underline"
                    @click="router.visit(DeckShowController({ deck: league.deck.id }).url)"
                >
                    {{ league.deck.name }}
                </span>
                <!-- Phantom badge -->
                <PhantomBadge v-if="league.phantom" />
            </div>

            <!-- Right: record + pips -->
            <div class="ml-auto flex shrink-0 items-center gap-3">
                <!-- Record -->
                <div class="flex items-center gap-1.5">
                    <Trophy v-if="isTrophy(league)" class="size-4 text-yellow-400" />
                    <Badge v-if="isInProgress(league)" variant="outline" class="text-xs text-muted-foreground"> In progress </Badge>
                    <span v-else class="text-sm font-semibold tabular-nums"> {{ runWins(league) }}-{{ runLosses(league) }} </span>
                </div>

                <!-- Pips -->
                <div class="flex items-center gap-1">
                    <ResultBadge :won="result === 'W'" v-for="(result, i) in league.results" :key="i" />
                </div>
            </div>
        </div>

        <!-- Matches table -->
        <div class="p-2">
            <CardContent class="overflow-hidden rounded-lg border border-accent/40 px-0 pb-0 shadow-md">
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
                            v-for="match in league.matches"
                            :key="match.id"
                            class="cursor-pointer"
                            @click="router.visit(MatchShowController({ id: match.id }).url)"
                        >
                            <TableCell>
                                <ResultBadge :won="match.result === 'W'" :showText="true" />
                            </TableCell>
                            <TableCell class="font-medium">
                                <span v-if="match.opponentName">{{ match.opponentName }}</span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell>
                                <span v-if="match.opponentArchetype" class="text-sm">{{ match.opponentArchetype }}</span>
                                <span v-else class="text-xs text-muted-foreground">Unknown</span>
                            </TableCell>
                            <TableCell class="text-sm tabular-nums">{{ match.games }}</TableCell>
                            <TableCell class="text-xs whitespace-nowrap text-muted-foreground">
                                {{ dayjs(match.startedAt).fromNow() }}
                            </TableCell>
                            <TableCell>
                                <Button size="sm" variant="ghost" @click.stop="router.visit(MatchShowController({ id: match.id }).url)">
                                    View
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </div>
    </Card>
</template>
