<script setup lang="ts">
import DeckShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { TournamentRun } from '@/types/tournaments';
import { router } from '@inertiajs/vue3';
import { Calendar, ChevronDown, Clock } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        tournament: TournamentRun;
        hideDeckIdentity?: boolean;
        defaultExpanded?: boolean;
    }>(),
    { hideDeckIdentity: false, defaultExpanded: false },
);

const expanded = ref(props.defaultExpanded);

const startedAbsolute = computed(() => {
    if (!props.tournament.startedAt) return null;
    return new Date(props.tournament.startedAt).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
    });
});

const avgMin = computed(() => {
    if (!props.tournament.avgMatchSeconds) return null;
    return Math.round(props.tournament.avgMatchSeconds / 60);
});

const onPlayTotal = computed(() => props.tournament.onPlayRecord.wins + props.tournament.onPlayRecord.losses);
const onDrawTotal = computed(() => props.tournament.onDrawRecord.wins + props.tournament.onDrawRecord.losses);

function toggle() {
    expanded.value = !expanded.value;
}

function formatDuration(seconds: number | null) {
    if (!seconds) return '—';
    return `${Math.round(seconds / 60)}m`;
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <button
            type="button"
            class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-muted/40"
            @click="toggle"
        >
            <div
                class="relative flex h-16 w-24 shrink-0 flex-col items-center justify-center rounded-md border border-muted-foreground/30 bg-muted/40 tabular-nums text-muted-foreground"
            >
                <span class="text-2xl leading-none font-bold">{{ tournament.wins }}-{{ tournament.losses }}</span>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <ManaSymbols
                        v-if="!hideDeckIdentity && tournament.deck?.colorIdentity"
                        :symbols="tournament.deck.colorIdentity"
                        class="shrink-0 [&_svg]:size-3"
                    />
                    <span
                        v-if="!hideDeckIdentity && tournament.deck"
                        class="cursor-pointer truncate font-medium hover:underline"
                        @click.stop="router.visit(DeckShowController({ deck: tournament.deck.id }).url)"
                    >
                        {{ tournament.deck.name }}
                    </span>
                    <span v-else class="truncate font-medium">{{ tournament.name }}</span>
                    <Badge variant="outline" class="shrink-0 text-[10px] tracking-wider uppercase">
                        {{ tournament.format }}
                    </Badge>
                    <span v-if="tournament.versionLabel" class="text-xs text-muted-foreground">
                        {{ tournament.versionLabel }}
                    </span>
                    <div class="flex items-center gap-1">
                        <ResultBadge v-for="(r, i) in tournament.results" :key="i" :won="r === 'W'" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span v-if="tournament.startedAtHuman" class="inline-flex items-center gap-1">
                        <Calendar class="size-3" />
                        {{ tournament.startedAtHuman }}
                        <span v-if="startedAbsolute"> · {{ startedAbsolute }}</span>
                    </span>
                    <span v-if="avgMin" class="inline-flex items-center gap-1">
                        <Clock class="size-3" /> avg
                        <span class="font-medium text-foreground">{{ avgMin }}m</span>
                    </span>
                    <span class="inline-flex items-center gap-1 tabular-nums">
                        {{ tournament.matches_count }}
                        {{ tournament.matches_count === 1 ? 'round' : 'rounds' }}
                    </span>
                    <span v-if="!hideDeckIdentity" class="truncate">{{ tournament.name }}</span>
                </div>
            </div>

            <div class="shrink-0">
                <ChevronDown
                    class="size-4 text-muted-foreground transition-transform"
                    :class="{ 'rotate-180': expanded }"
                />
            </div>
        </button>

        <div v-if="expanded" class="flex flex-col gap-4 border-t border-border p-4">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="text-xl font-semibold text-emerald-400 tabular-nums">{{ tournament.gameWins }}</div>
                    <div class="text-xs text-muted-foreground">Game wins</div>
                </div>
                <div>
                    <div class="text-xl font-semibold text-destructive tabular-nums">{{ tournament.gameLosses }}</div>
                    <div class="text-xs text-muted-foreground">Game losses</div>
                </div>
                <div>
                    <div class="text-xl font-semibold tabular-nums">{{ onPlayTotal }}</div>
                    <div class="text-xs text-muted-foreground">On the play</div>
                </div>
                <div>
                    <div class="text-xl font-semibold tabular-nums">{{ onDrawTotal }}</div>
                    <div class="text-xs text-muted-foreground">On the draw</div>
                </div>
            </div>

            <div class="isolate overflow-hidden rounded-md border border-border bg-card">
                <Table class="table-fixed">
                    <TableHeader class="!static !backdrop-blur-none">
                        <TableRow>
                            <TableHead class="w-[60px]">Round</TableHead>
                            <TableHead class="w-[100px]">Result</TableHead>
                            <TableHead class="w-[160px]">Opponent</TableHead>
                            <TableHead>Vs</TableHead>
                            <TableHead class="w-[120px] text-center">Game 1</TableHead>
                            <TableHead class="w-[120px] text-center">Game 2</TableHead>
                            <TableHead class="w-[120px] text-center">Game 3</TableHead>
                            <TableHead class="w-[80px] text-right">Time</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="match in tournament.matches"
                            :key="match.id"
                            class="cursor-pointer"
                            @click="router.visit(MatchShowController({ id: match.id }).url)"
                        >
                            <TableCell class="font-mono tabular-nums">R{{ match.roundNumber }}</TableCell>
                            <TableCell>
                                <ResultBadge :won="match.result === 'W'" :show-text="true" />
                            </TableCell>
                            <TableCell class="truncate font-medium">
                                <span v-if="match.opponentName">{{ match.opponentName }}</span>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="truncate">
                                <span v-if="match.opponentArchetype" class="text-sm">{{ match.opponentArchetype }}</span>
                                <span v-else class="text-xs text-muted-foreground">Unknown</span>
                            </TableCell>
                            <TableCell v-for="i in 3" :key="i" class="text-center text-sm">
                                <template v-if="match.gameResults[i - 1]">
                                    <span
                                        :class="
                                            match.gameResults[i - 1].result === 'W' ? 'text-success' : 'text-destructive'
                                        "
                                    >
                                        {{ match.gameResults[i - 1].result === 'W' ? 'Win' : 'Loss' }}
                                    </span>
                                    <span
                                        v-if="match.gameResults[i - 1].onPlay !== null"
                                        class="ml-1 text-xs text-muted-foreground"
                                    >
                                        ({{ match.gameResults[i - 1].onPlay ? 'OTP' : 'OTD' }})
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right text-muted-foreground tabular-nums">
                                {{ formatDuration(match.durationSeconds) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </Card>
</template>
