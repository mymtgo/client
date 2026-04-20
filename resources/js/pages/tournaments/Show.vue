<script setup lang="ts">
import IndexController from '@/actions/App/Http/Controllers/Tournaments/IndexController';
import DashboardController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronUp, ChevronDown, Minus } from 'lucide-vue-next';
import { computed, onUnmounted, ref } from 'vue';
import YourRounds from './partials/YourRounds.vue';

type Tournament = {
    id: number;
    name: string | null;
    format: string | null;
    description: string | null;
    tournament_structure: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    player_count: number;
    min_players: number | null;
    max_players: number | null;
    started_at: string | null;
    ended_at: string | null;
    participated: boolean;
};

type Standing = {
    id: number;
    login_id: number;
    username: string | null;
    rank: number;
    points: number;
    wins: number;
    losses: number;
    draws: number;
    opponent_match_win_pct: number | null;
    game_win_pct: number | null;
    is_local: boolean;
};

type TimelineEvent = {
    id: number;
    round: number | null;
    event_type: string;
    login_id: number | null;
    username: string | null;
    payload: Record<string, unknown> | null;
    occurred_at: string;
};

type YourRound = {
    match_id: number;
    round: number;
    opponent_username: string | null;
    opponent_login_id: number | null;
    opponent_rank: number | null;
    result: string;
    deck_name: string | null;
    deck_id: number | null;
};

const props = defineProps<{
    tournament: Tournament;
    standingsByRound: Record<number, Standing[]>;
    rounds: number[];
    timelineEvents: TimelineEvent[];
    eliminatedIds: number[];
    latestRound: number;
    fromDeck: number | null;
    yourRounds: YourRound[];
    eliminatedAfterRound: number | null;
}>();

const selectedRound = ref(props.latestRound);
const standings = computed(() => props.standingsByRound[selectedRound.value] ?? []);

const previousRound = computed(() => {
    const idx = props.rounds.indexOf(selectedRound.value);
    return idx > 0 ? props.rounds[idx - 1] : null;
});

const previousRankMap = computed(() => {
    if (!previousRound.value) return {};
    const prev = props.standingsByRound[previousRound.value] ?? [];
    const map: Record<number, number> = {};
    for (const s of prev) {
        map[s.login_id] = s.rank;
    }
    return map;
});

function rankMovement(standing: Standing): 'up' | 'down' | 'same' | 'new' {
    const prevRank = previousRankMap.value[standing.login_id];
    if (prevRank === undefined) return 'new';
    if (standing.rank < prevRank) return 'up';
    if (standing.rank > prevRank) return 'down';
    return 'same';
}

function rankDelta(standing: Standing): number {
    const prevRank = previousRankMap.value[standing.login_id];
    if (prevRank === undefined) return 0;
    return Math.abs(prevRank - standing.rank);
}

const isActive = computed(() => props.tournament.state !== 'completed');

let pollInterval: ReturnType<typeof setInterval> | null = null;
if (isActive.value) {
    pollInterval = setInterval(() => {
        router.reload({ only: ['tournament', 'standingsByRound', 'rounds', 'timelineEvents', 'eliminatedIds', 'latestRound'] });
    }, 30000);
}

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});

function stateLabel(state: string): string {
    return state
        .split('_')
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

function stateColor(state: string): string {
    if (state === 'completed') return 'text-zinc-400';
    if (state === 'round_in_progress') return 'text-green-500';
    if (state === 'between_rounds') return 'text-yellow-500';
    return 'text-blue-500';
}

function stateBadgeClass(state: string): string {
    if (state === 'completed') return 'bg-zinc-800 text-zinc-400';
    if (state === 'round_in_progress') return 'bg-green-500/10 text-green-400';
    if (state === 'between_rounds') return 'bg-yellow-500/10 text-yellow-400';
    return 'bg-blue-500/10 text-blue-400';
}

function formatTime(dateStr: string | null): string {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function relativeTime(dateStr: string | null): string {
    if (!dateStr) return '—';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function formatPct(value: number | null): string {
    if (value === null) return '—';
    return (value * 100).toFixed(1) + '%';
}

const eliminatedSet = computed(() => new Set(props.eliminatedIds));

const localStanding = computed(() => standings.value.find((s) => s.is_local) ?? null);

const showPinnedLocal = computed(() => {
    if (!localStanding.value) return false;
    return localStanding.value.rank > 15;
});

function formatRecord(standing: Standing): string {
    if (standing.draws > 0) {
        return `${standing.wins}-${standing.draws}-${standing.losses}`;
    }
    return `${standing.wins}-${standing.losses}`;
}

const groupedTimeline = computed(() => {
    const groups: Record<string, TimelineEvent[]> = {};
    for (const event of props.timelineEvents) {
        const key = event.round !== null ? `Round ${event.round}` : 'General';
        if (!groups[key]) groups[key] = [];
        groups[key].push(event);
    }
    return groups;
});

const sortedTimelineGroups = computed(() => {
    return Object.entries(groupedTimeline.value).sort(([a], [b]) => {
        const aNum = a === 'General' ? -1 : parseInt(a.replace('Round ', ''), 10);
        const bNum = b === 'General' ? -1 : parseInt(b.replace('Round ', ''), 10);
        return bNum - aNum;
    });
});

function eventDotClass(eventType: string): string {
    if (eventType === 'state_changed') return 'bg-blue-500';
    if (eventType === 'player_eliminated') return 'bg-red-500';
    if (eventType === 'round_result') return 'bg-green-500';
    return 'bg-zinc-500';
}

function eventDescription(event: TimelineEvent): string {
    if (event.event_type === 'state_changed') {
        const state = (event.payload?.to_state as string) ?? '';
        return `Tournament moved to ${stateLabel(state)}`;
    }
    if (event.event_type === 'player_eliminated') {
        const name = event.username ?? `Player #${event.login_id}`;
        const reason = event.payload?.reason as string | undefined;
        return reason === 'Drop' ? `${name} dropped` : `${name} eliminated`;
    }
    if (event.event_type === 'round_result') {
        const round = event.round ?? '?';
        const players = event.payload?.player_count as number | undefined;
        return players !== undefined
            ? `Round ${round} standings posted (${players} players)`
            : `Round ${round} standings posted`;
    }
    return event.event_type.replace(/_/g, ' ');
}

function eventTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function goBackToTournaments() {
    if (window.history.length > 1) {
        window.history.back();
        return;
    }
    router.visit(IndexController.url());
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Back navigation -->
        <div class="flex items-center gap-2">
            <Button variant="ghost" size="sm" @click="goBackToTournaments">
                <ArrowLeft class="size-3.5" />
                Back to Tournaments
            </Button>
            <Button v-if="fromDeck !== null" variant="ghost" size="sm" as-child>
                <Link :href="DashboardController.url({ deck: fromDeck })">
                    <ArrowLeft class="size-3.5" />
                    Back to Deck
                </Link>
            </Button>
        </div>

        <!-- 2-column layout: content left, timeline full-height right -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_320px] lg:items-start">
            <!-- Left column: Details + Standings + Your Rounds stacked -->
            <div class="flex flex-col gap-4">
            <!-- Details -->
            <Card class="py-0">
                <CardContent class="flex flex-col gap-3 p-4">
                    <div>
                        <h1 class="text-base font-semibold leading-tight">
                            {{ tournament.name ?? 'Tournament' }}
                        </h1>
                        <p v-if="tournament.format" class="mt-0.5 text-sm text-zinc-400">{{ tournament.format }}</p>
                    </div>

                    <!-- Status badge -->
                    <span
                        class="inline-flex w-fit items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="stateBadgeClass(tournament.state)"
                    >
                        {{ stateLabel(tournament.state) }}
                    </span>

                    <div class="flex flex-col gap-2 text-sm">
                        <!-- Structure -->
                        <div v-if="tournament.tournament_structure" class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Structure</span>
                            <span class="text-zinc-200">{{ tournament.tournament_structure }}</span>
                        </div>

                        <!-- Round progress -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Round</span>
                            <span class="tabular-nums text-zinc-200">
                                <template v-if="tournament.current_round !== null && tournament.max_rounds !== null">
                                    {{ tournament.current_round }} / {{ tournament.max_rounds }}
                                </template>
                                <template v-else-if="tournament.current_round !== null">
                                    {{ tournament.current_round }}
                                </template>
                                <template v-else>—</template>
                            </span>
                        </div>

                        <!-- Players -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Players</span>
                            <span class="tabular-nums text-zinc-200">
                                <template v-if="tournament.max_players !== null">
                                    {{ tournament.player_count }} / {{ tournament.max_players }}
                                </template>
                                <template v-else>{{ tournament.player_count }}</template>
                                <template v-if="tournament.min_players !== null">
                                    <span class="text-zinc-500"> (min {{ tournament.min_players }})</span>
                                </template>
                            </span>
                        </div>

                        <!-- Started -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Started</span>
                            <span class="text-zinc-200" :title="formatTime(tournament.started_at)">
                                {{ relativeTime(tournament.started_at) }}
                            </span>
                        </div>

                        <!-- Ended -->
                        <div v-if="tournament.ended_at" class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Ended</span>
                            <span class="text-zinc-200" :title="formatTime(tournament.ended_at)">
                                {{ relativeTime(tournament.ended_at) }}
                            </span>
                        </div>

                        <!-- Participation -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Participation</span>
                            <span v-if="tournament.participated" class="text-green-400">Participated</span>
                            <span v-else class="text-zinc-500">Not participated</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Standings -->
            <Card class="overflow-hidden py-0 gap-0">
                <!-- Round tabs -->
                <div v-if="rounds.length > 1" class="px-3 pt-3">
                    <Tabs :model-value="String(selectedRound)" @update:model-value="(v: string) => selectedRound = Number(v)">
                        <TabsList>
                            <TabsTrigger
                                v-for="round in rounds"
                                :key="round"
                                :value="String(round)"
                            >
                                Round {{ round }}
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>
                <div class="max-h-[600px] overflow-y-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead v-if="previousRound" class="sticky top-0 bg-zinc-900 w-10"></TableHead>
                                <TableHead class="sticky top-0 bg-zinc-900 w-12">#</TableHead>
                                <TableHead class="sticky top-0 bg-zinc-900">Player</TableHead>
                                <TableHead class="sticky top-0 bg-zinc-900 tabular-nums text-right">Pts</TableHead>
                                <TableHead class="sticky top-0 bg-zinc-900 text-right">Record</TableHead>
                                <TableHead class="sticky top-0 bg-zinc-900 tabular-nums text-right">OMW%</TableHead>
                                <TableHead class="sticky top-0 bg-zinc-900 tabular-nums text-right">GW%</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-if="standings.length === 0">
                                <TableRow>
                                    <TableCell :colspan="previousRound ? 7 : 6" class="py-12 text-center text-sm text-zinc-500">
                                        No standings available yet.
                                    </TableCell>
                                </TableRow>
                            </template>
                            <TableRow
                                v-for="standing in standings"
                                :key="standing.id"
                                :class="[
                                    standing.is_local ? 'bg-blue-500/10' : '',
                                    eliminatedSet.has(standing.login_id) ? 'text-zinc-500 line-through' : '',
                                ]"
                            >
                                <TableCell v-if="previousRound" class="text-xs w-10 px-2">
                                    <div class="flex items-center gap-0.5">
                                        <template v-if="rankMovement(standing) === 'up'">
                                            <ChevronUp class="size-3.5 text-green-500" />
                                            <span class="text-green-500 tabular-nums">{{ rankDelta(standing) }}</span>
                                        </template>
                                        <template v-else-if="rankMovement(standing) === 'down'">
                                            <ChevronDown class="size-3.5 text-red-500" />
                                            <span class="text-red-500 tabular-nums">{{ rankDelta(standing) }}</span>
                                        </template>
                                        <template v-else-if="rankMovement(standing) === 'same'">
                                            <Minus class="size-3 text-zinc-600" />
                                        </template>
                                    </div>
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-zinc-400">
                                    {{ standing.rank }}
                                </TableCell>
                                <TableCell
                                    class="text-sm"
                                    :class="standing.is_local ? 'font-medium text-blue-400' : ''"
                                >
                                    {{ standing.username ?? `#${standing.login_id}` }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right">
                                    {{ standing.points }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right">
                                    {{ formatRecord(standing) }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                    {{ formatPct(standing.opponent_match_win_pct) }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                    {{ formatPct(standing.game_win_pct) }}
                                </TableCell>
                            </TableRow>

                        </TableBody>
                    </Table>
                </div>

                <!-- Pinned local standing — always visible below scroll area -->
                <div v-if="showPinnedLocal && localStanding" class="border-t border-blue-500/30 bg-blue-500/5">
                    <Table>
                        <TableBody>
                            <TableRow class="bg-blue-500/10">
                                <TableCell v-if="previousRound" class="text-xs w-10 px-2">
                                    <div class="flex items-center gap-0.5">
                                        <template v-if="rankMovement(localStanding) === 'up'">
                                            <ChevronUp class="size-3.5 text-green-500" />
                                            <span class="text-green-500 tabular-nums">{{ rankDelta(localStanding) }}</span>
                                        </template>
                                        <template v-else-if="rankMovement(localStanding) === 'down'">
                                            <ChevronDown class="size-3.5 text-red-500" />
                                            <span class="text-red-500 tabular-nums">{{ rankDelta(localStanding) }}</span>
                                        </template>
                                        <template v-else-if="rankMovement(localStanding) === 'same'">
                                            <Minus class="size-3 text-zinc-600" />
                                        </template>
                                    </div>
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-zinc-400 w-12">
                                    {{ localStanding.rank }}
                                </TableCell>
                                <TableCell class="text-sm font-medium text-blue-400">
                                    {{ localStanding.username ?? `#${localStanding.login_id}` }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right">
                                    {{ localStanding.points }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right">
                                    {{ formatRecord(localStanding) }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                    {{ formatPct(localStanding.opponent_match_win_pct) }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                    {{ formatPct(localStanding.game_win_pct) }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </Card>

            <!-- Your Rounds (participated only) -->
            <Card v-if="tournament.participated" class="py-0">
                <CardContent class="p-4">
                    <YourRounds :rounds="yourRounds" :eliminated-after-round="eliminatedAfterRound" />
                </CardContent>
            </Card>
            </div>

            <!-- Right column: Timeline (spans full height) -->
            <Card class="py-0 lg:sticky lg:top-4 lg:max-h-[calc(100vh-8rem)]">
                <CardContent class="p-4">
                    <h2 class="mb-3 text-sm font-semibold text-zinc-300">Timeline</h2>
                    <div v-if="timelineEvents.length === 0" class="py-8 text-center text-sm text-zinc-500">
                        No events yet.
                    </div>
                    <div v-else class="flex flex-col gap-4 overflow-y-auto max-h-[560px]">
                        <div v-for="([group, events]) in sortedTimelineGroups" :key="group">
                            <p class="mb-1.5 text-xs font-semibold text-zinc-500 uppercase tracking-wide">{{ group }}</p>
                            <div class="flex flex-col gap-2">
                                <div v-for="event in events" :key="event.id" class="flex items-start gap-2">
                                    <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="eventDotClass(event.event_type)" />
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-sm leading-snug text-zinc-200">{{ eventDescription(event) }}</span>
                                        <span class="text-xs text-zinc-500">{{ eventTime(event.occurred_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
