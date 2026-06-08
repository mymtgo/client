<script setup lang="ts">
import DeckShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import LeagueContextMenu from '@/components/leagues/LeagueContextMenu.vue';
import AddMatchDialog from '@/components/leagues/AddMatchDialog.vue';
import LeagueDropDialog from '@/components/leagues/LeagueDropDialog.vue';
import LeaguesUnlinkMatchController from '@/actions/App/Http/Controllers/Leagues/UnlinkMatchController';
import LeagueNotesDialog from '@/components/leagues/LeagueNotesDialog.vue';
import LeagueResultBadge from '@/components/leagues/LeagueResultBadge.vue';
import LeagueScreenshot from '@/components/leagues/LeagueScreenshot.vue';
import MatchRowMenu from '@/components/matches/MatchRowMenu.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useScreenshot } from '@/composables/useScreenshot';
import type { LeagueRun, LeagueTimeOfDay } from '@/types/leagues';
import { router } from '@inertiajs/vue3';
import { Calendar, ChevronDown, CircleX, Clock, Moon, PencilLine, Plus, Sun, Sunrise, Sunset, Trash2, Wrench } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        league: LeagueRun;
        hideDeckIdentity?: boolean;
        defaultExpanded?: boolean;
        archetypes?: App.Data.Front.ArchetypeData[];
    }>(),
    { hideDeckIdentity: false, defaultExpanded: false, archetypes: () => [] },
);

const expanded = ref(props.defaultExpanded || props.league.classification === 'LIVE');

const wins = computed(() => props.league.results.filter((r) => r === 'W').length);
const losses = computed(() => props.league.results.filter((r) => r === 'L').length);

const startedAbsolute = computed(() => {
    if (!props.league.startedAt) return null;
    return new Date(props.league.startedAt).toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
});

const avgMin = computed(() => {
    if (!props.league.avgMatchSeconds) return null;
    return Math.round(props.league.avgMatchSeconds / 60);
});

const tixClass = computed(() => {
    if (props.league.tixDelta === null) return '';
    if (props.league.tixDelta > 0) return 'text-emerald-400';
    if (props.league.tixDelta < 0) return 'text-destructive';
    return 'text-muted-foreground';
});

const todIconMap: Record<LeagueTimeOfDay, typeof Sun> = {
    morning: Sunrise,
    afternoon: Sun,
    evening: Sunset,
    night: Moon,
};

const todIcon = computed(() => (props.league.timeOfDay ? todIconMap[props.league.timeOfDay] : null));
const todLabel = computed(() => {
    const t = props.league.timeOfDay;
    return t ? t.charAt(0).toUpperCase() + t.slice(1) : null;
});

const onPlayTotal = computed(() => props.league.onPlayRecord.wins + props.league.onPlayRecord.losses);
const onDrawTotal = computed(() => props.league.onDrawRecord.wins + props.league.onDrawRecord.losses);

const screenshotRef = ref<InstanceType<typeof LeagueScreenshot> | null>(null);
const showScreenshot = ref(false);
const { capture, capturing } = useScreenshot();

const notesDialogRef = ref<InstanceType<typeof LeagueNotesDialog> | null>(null);
const dropDialogRef = ref<InstanceType<typeof LeagueDropDialog> | null>(null);

const addMatchDialogRef = ref<InstanceType<typeof AddMatchDialog> | null>(null);

function handleAddMatch() {
    addMatchDialogRef.value?.open(props.league.id, props.league.matches.length);
}

function handleUnlinkMatch(matchId: number) {
    router.delete(LeaguesUnlinkMatchController({ league: props.league.id, mtgoMatch: matchId }).url, {
        preserveScroll: true,
    });
}

const isManual = computed(() => props.league.manual === true);
const canAddMatch = computed(() => isManual.value && props.league.matches.length < 5);
const isEmptyManual = computed(() => isManual.value && props.league.matches.length === 0);

const canDrop = computed(() => props.league.state === 'active');

function handleEditNotes() {
    notesDialogRef.value?.openForLeague(props.league.id, props.league.notes);
}

function handleDrop() {
    dropDialogRef.value?.openForLeague(props.league.id);
}

async function handleScreenshot() {
    showScreenshot.value = true;
    await nextTick();
    const el = screenshotRef.value?.$el as HTMLElement | undefined;
    if (el) {
        await capture(el);
    }
    showScreenshot.value = false;
}

function handleCopySummary() {
    const lines: string[] = [];
    const deckName = props.league.deck?.name ?? 'Unknown deck';
    const versionPart = props.league.versionLabel ? ` ${props.league.versionLabel}` : '';
    lines.push(`${deckName} ${props.league.format}${versionPart}`);
    if (props.league.startedAtHuman) {
        lines.push(`${props.league.startedAtHuman} · ${wins.value}-${losses.value}`);
    }
    for (const m of props.league.matches) {
        const opp = m.opponentArchetype ?? m.opponentName ?? 'Unknown';
        lines.push(`${m.result} vs ${opp}`);
    }
    navigator.clipboard.writeText(lines.join('\n'));
}

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
        <button type="button" class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-muted/40" @click="toggle">
            <LeagueResultBadge :classification="league.classification" :wins="wins" :losses="losses" :live-round="league.liveRound" />

            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <ManaSymbols
                        v-if="!hideDeckIdentity && league.deck?.colorIdentity"
                        :symbols="league.deck.colorIdentity"
                        class="shrink-0 [&_svg]:size-3"
                    />
                    <span
                        v-if="!hideDeckIdentity && league.deck"
                        class="cursor-pointer truncate font-medium hover:underline"
                        @click.stop="router.visit(DeckShowController({ deck: league.deck.id }).url)"
                    >
                        {{ league.deck.name }}
                    </span>
                    <Badge variant="outline" class="shrink-0 text-[10px] tracking-wider uppercase" v-if="!hideDeckIdentity && league.deck">
                        {{ league.format }}
                    </Badge>
                    <Badge
                        v-if="isManual"
                        variant="outline"
                        class="shrink-0 gap-1 border-primary/40 bg-primary/10 text-[10px] tracking-wider text-primary uppercase"
                        title="Manually created league"
                    >
                        <Wrench class="size-3" />
                        Manual
                    </Badge>
                    <span v-if="league.versionLabel" class="text-xs text-muted-foreground">
                        {{ league.versionLabel }}
                    </span>
                    <Badge
                        v-if="league.state === 'dropped'"
                        variant="outline"
                        class="shrink-0 gap-1 border-destructive/40 bg-destructive/10 text-[10px] tracking-wider text-destructive uppercase"
                        :title="league.droppedAtHuman ? `Dropped ${league.droppedAtHuman}` : 'Dropped before completion'"
                    >
                        <CircleX class="size-3" />
                        Dropped
                    </Badge>
                    <div class="flex items-center gap-1">
                        <template v-for="(r, i) in league.results" :key="i">
                            <div v-if="r === null" class="size-2 rounded-full border border-muted-foreground/40" />
                            <ResultBadge v-else :won="r === 'W'" />
                        </template>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span class="inline-flex items-center gap-1">
                        <Calendar class="size-3" />
                        {{ league.startedAtHuman }}
                        <span v-if="startedAbsolute"> · {{ startedAbsolute }}</span>
                    </span>
                    <span v-if="avgMin" class="inline-flex items-center gap-1">
                        <Clock class="size-3" /> avg
                        <span class="font-medium text-foreground">{{ avgMin }}m</span>
                    </span>
                    <span v-if="todLabel" class="inline-flex items-center gap-1"> <component :is="todIcon" class="size-3" /> {{ todLabel }} </span>
                    <span v-if="league.tixDelta !== null" class="inline-flex items-center gap-1 tabular-nums" :class="tixClass">
                        {{ league.tixDelta > 0 ? '+' : '' }}{{ league.tixDelta.toFixed(0) }} tix
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <LeagueContextMenu
                    :disabled="capturing"
                    :can-drop="canDrop"
                    @screenshot="handleScreenshot"
                    @copy-summary="handleCopySummary"
                    @edit-notes="handleEditNotes"
                    @drop="handleDrop"
                />
                <ChevronDown class="size-4 text-muted-foreground transition-transform" :class="{ 'rotate-180': expanded }" />
            </div>
        </button>

        <div v-if="expanded" class="flex flex-col gap-4 border-t border-border p-4">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="text-xl font-semibold text-emerald-400 tabular-nums">
                        {{ league.gameWins }}
                    </div>
                    <div class="text-xs text-muted-foreground">Game wins</div>
                </div>
                <div>
                    <div class="text-xl font-semibold text-destructive tabular-nums">
                        {{ league.gameLosses }}
                    </div>
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

            <template v-if="!isEmptyManual">
                <div class="isolate overflow-hidden rounded-md border border-border bg-card">
                    <Table class="table-fixed">
                        <TableHeader class="!static !backdrop-blur-none">
                            <TableRow>
                                <TableHead class="w-[60px] text-center">Match</TableHead>
                                <TableHead class="w-[110px]">Result</TableHead>
                                <TableHead class="w-[160px]">Opponent</TableHead>
                                <TableHead>Vs</TableHead>
                                <TableHead class="w-[120px] text-center">Game 1</TableHead>
                                <TableHead class="w-[120px] text-center">Game 2</TableHead>
                                <TableHead class="w-[120px] text-center">Game 3</TableHead>
                                <TableHead class="w-[80px] text-right">Time</TableHead>
                                <TableHead v-if="isManual" class="w-[60px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <MatchRowMenu
                                v-for="(match, matchIndex) in league.matches"
                                :key="match.id"
                                :match-id="match.id"
                                :format="league.format"
                                :current-archetype-id="match.opponentArchetypeId"
                                :notes="match.notes"
                                :archetypes="archetypes"
                            >
                            <TableRow
                                class="cursor-pointer"
                                @click="router.visit(MatchShowController({ id: match.id }).url)"
                            >
                                <TableCell class="text-center text-sm text-muted-foreground tabular-nums">
                                    {{ matchIndex + 1 }}
                                </TableCell>
                                <TableCell>
                                    <ResultBadge :won="match.result === 'W'" :show-text="true" />
                                </TableCell>
                                <TableCell class="truncate font-medium">
                                    <span v-if="match.opponentName">{{ match.opponentName }}</span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </TableCell>
                                <TableCell class="truncate">
                                    <span v-if="match.opponentArchetype" class="text-sm">
                                        {{ match.opponentArchetype }}
                                    </span>
                                    <span v-else class="text-xs text-muted-foreground">Unknown</span>
                                </TableCell>
                                <TableCell v-for="i in 3" :key="i" class="text-center text-sm">
                                    <template v-if="match.gameResults[i - 1]">
                                        <span :class="match.gameResults[i - 1].result === 'W' ? 'text-success' : 'text-destructive'">
                                            {{ match.gameResults[i - 1].result === 'W' ? 'Win' : 'Loss' }}
                                        </span>
                                        <span v-if="match.gameResults[i - 1].onPlay !== null" class="ml-1 text-xs text-muted-foreground">
                                            ({{ match.gameResults[i - 1].onPlay ? 'OTP' : 'OTD' }})
                                        </span>
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">
                                    {{ formatDuration(match.durationSeconds) }}
                                </TableCell>
                                <TableCell v-if="isManual" class="text-right">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-7 text-muted-foreground hover:text-destructive"
                                        title="Remove from league"
                                        @click.stop="handleUnlinkMatch(match.id)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                            </MatchRowMenu>
                        </TableBody>
                    </Table>
                </div>
            </template>

            <div v-if="isEmptyManual" class="flex flex-col items-center gap-2 rounded-md border border-dashed border-border bg-card/50 p-8 text-center">
                <p class="text-sm font-medium">No matches yet</p>
                <p class="text-xs text-muted-foreground">Pick existing matches played with this deck to build the league.</p>
            </div>

            <button
                v-if="canAddMatch"
                type="button"
                class="flex w-full items-center justify-center gap-2 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground hover:bg-muted/40 hover:text-foreground"
                @click="handleAddMatch"
            >
                <Plus class="size-4" />
                Add match
            </button>

            <section>
                <div class="mb-2 flex items-center gap-2">
                    <h4 class="text-[10px] font-medium tracking-widest text-muted-foreground uppercase">Notes</h4>
                    <Button variant="ghost" size="icon" class="size-5" aria-label="Edit notes" @click="handleEditNotes">
                        <PencilLine class="size-3" />
                    </Button>
                </div>
                <p v-if="league.notes" class="text-sm whitespace-pre-wrap text-foreground">
                    {{ league.notes }}
                </p>
                <button
                    v-else
                    type="button"
                    class="w-full rounded-md border border-dashed border-border p-3 text-left text-sm text-muted-foreground italic hover:bg-muted/40"
                    @click="handleEditNotes"
                >
                    Click to add notes for this league…
                </button>
            </section>
        </div>
    </Card>

    <div v-if="showScreenshot" style="position: fixed; top: -9999px; left: -9999px; pointer-events: none">
        <LeagueScreenshot ref="screenshotRef" :league="league" />
    </div>

    <LeagueNotesDialog ref="notesDialogRef" />
    <LeagueDropDialog ref="dropDialogRef" />
    <AddMatchDialog ref="addMatchDialogRef" />
</template>
