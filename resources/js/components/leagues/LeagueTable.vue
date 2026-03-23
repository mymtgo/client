<script lang="ts" setup>
import AbandonController from '@/actions/App/Http/Controllers/Leagues/AbandonController';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import { useLeagueScreenshot } from '@/composables/useLeagueScreenshot';
import { Camera, Ellipsis, Trash2, Trophy } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';
import LeagueScreenshot from './LeagueScreenshot.vue';
import ResultBadge from '../matches/ResultBadge.vue';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';
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
    versionLabel: string | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    state: 'active' | 'complete' | 'partial';
    matches: LeagueMatch[];
};

defineProps<{
    league: LeagueRun;
}>();

const runWins = (r: LeagueRun) => r.results.filter((x) => x === 'W').length;
const runLosses = (r: LeagueRun) => r.results.filter((x) => x === 'L').length;
const isComplete = (r: LeagueRun) => r.state === 'complete';
const isPartial = (r: LeagueRun) => r.state === 'partial';
const isActive = (r: LeagueRun) => r.state === 'active';
const isTrophy = (r: LeagueRun) => runWins(r) === 5 && isComplete(r) && !r.phantom;

function abandonLeague(league: LeagueRun) {
    router.delete(AbandonController.url(league.id), { preserveScroll: true });
}

const screenshotRef = ref<InstanceType<typeof LeagueScreenshot> | null>(null);
const showScreenshot = ref(false);
const { capture, capturing } = useLeagueScreenshot();

async function copyScreenshot() {
    showScreenshot.value = true;
    await nextTick();
    const el = screenshotRef.value?.$el as HTMLElement | undefined;
    if (el) {
        await capture(el);
    }
    showScreenshot.value = false;
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <!-- Run header -->
        <div class="flex items-center gap-4 bg-muted/40 px-4 py-3">
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
                <span v-if="league.versionLabel" class="text-xs text-muted-foreground">{{ league.versionLabel }}</span>
                <PhantomBadge v-if="league.phantom" />
            </div>

            <!-- Right: record + pips -->
            <div class="ml-auto flex shrink-0 items-center gap-3">
                <div class="flex items-center gap-1.5">
                    <Trophy v-if="isTrophy(league)" class="size-4 text-yellow-400" />
                    <Badge v-if="isActive(league)" variant="outline" class="text-xs text-muted-foreground"> In progress </Badge>
                    <Badge v-else-if="isPartial(league)" variant="secondary" class="text-xs text-muted-foreground"> Partial </Badge>
                    <span v-else class="text-sm font-semibold tabular-nums"> {{ runWins(league) }}-{{ runLosses(league) }} </span>
                </div>

                <div class="flex items-center gap-1">
                    <template v-for="(result, i) in league.results" :key="i">
                        <div v-if="result === null" class="h-2 w-2 rounded-full border border-muted-foreground/40" />
                        <ResultBadge v-else :won="result === 'W'" />
                    </template>
                </div>

                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 shrink-0"
                    :disabled="capturing"
                    @click.stop="copyScreenshot"
                >
                    <Camera class="size-4" />
                </Button>

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

        <!-- Matches table -->
        <div class="border-t">
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
        </div>

    </Card>
    <div v-if="showScreenshot" style="position: fixed; top: -9999px; left: -9999px; pointer-events: none;">
        <LeagueScreenshot ref="screenshotRef" :league="league" />
    </div>
</template>
