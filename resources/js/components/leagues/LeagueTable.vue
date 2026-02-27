<script lang="ts" setup>
import AbandonController from '@/actions/App/Http/Controllers/Leagues/AbandonController';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import { ChevronRight, Ellipsis, Trash2, Trophy } from 'lucide-vue-next';
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

function abandonLeague(league: LeagueRun) {
    router.delete(AbandonController.url(league.id), { preserveScroll: true });
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <Collapsible v-slot="{ open }">
            <!-- Run header (clickable trigger) -->
            <CollapsibleTrigger as-child>
                <div class="flex cursor-pointer items-center gap-4 bg-muted/40 px-4 py-3 transition-colors hover:bg-muted/60">
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground transition-transform" :class="open && 'rotate-90'" />

                    <!-- Left: date + format + deck -->
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="text-sm whitespace-nowrap text-muted-foreground">
                            {{ dayjs(league.startedAt).fromNow() }}
                        </span>
                        <Badge variant="outline" class="shrink-0">{{ league.format }}</Badge>
                        <span
                            v-if="league.deck"
                            class="cursor-pointer truncate text-sm font-medium text-primary hover:underline"
                            @click.stop="router.visit(DeckShowController({ deck: league.deck.id }).url)"
                        >
                            {{ league.deck.name }}
                        </span>
                        <PhantomBadge v-if="league.phantom" />
                    </div>

                    <!-- Right: record + pips -->
                    <div class="ml-auto flex shrink-0 items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <Trophy v-if="isTrophy(league)" class="size-4 text-yellow-400" />
                            <Badge v-if="isInProgress(league)" variant="outline" class="text-xs text-muted-foreground"> In progress </Badge>
                            <span v-else class="text-sm font-semibold tabular-nums"> {{ runWins(league) }}-{{ runLosses(league) }} </span>
                        </div>

                        <div class="flex items-center gap-1">
                            <ResultBadge :won="result === 'W'" v-for="(result, i) in league.results" :key="i" />
                        </div>

                        <DropdownMenu v-if="league.phantom">
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="icon" class="size-7 shrink-0" @click.stop>
                                    <Ellipsis class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem class="text-destructive" @click="abandonLeague(league)">
                                    <Trash2 class="size-4" />
                                    Abandon
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </CollapsibleTrigger>

            <!-- Matches table (collapsed by default) -->
            <CollapsibleContent>
                <div class="border-t p-2">
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
            </CollapsibleContent>
        </Collapsible>
    </Card>
</template>
