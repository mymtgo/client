<script setup lang="ts">
import IndexController from '@/actions/App/Http/Controllers/Challenges/IndexController';
import DashboardController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, onUnmounted } from 'vue';

type Challenge = {
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
    match_record: string;
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

const props = defineProps<{
    challenge: Challenge;
    standings: Standing[];
    timelineEvents: TimelineEvent[];
    eliminatedIds: number[];
    latestRound: number;
    fromDeck: number | null;
}>();

const isActive = computed(() => props.challenge.state !== 'completed');

let pollInterval: ReturnType<typeof setInterval> | null = null;
if (isActive.value) {
    pollInterval = setInterval(() => {
        router.reload({ only: ['challenge', 'standings', 'timelineEvents', 'eliminatedIds', 'latestRound'] });
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

const localStanding = computed(() => props.standings.find((s) => s.is_local) ?? null);

const showPinnedLocal = computed(() => {
    if (!localStanding.value) return false;
    return localStanding.value.rank > 15;
});

function aggregateRecord(matchRecord: string): string {
    let wins = 0;
    let losses = 0;
    for (const part of matchRecord.split(',')) {
        const trimmed = part.trim();
        const [w, l] = trimmed.split('-').map(Number);
        if (!isNaN(w)) wins += w;
        if (!isNaN(l)) losses += l;
    }
    return `${wins}-${losses}`;
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
        return `Challenge moved to ${stateLabel(state)}`;
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
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Back navigation -->
        <div class="flex items-center gap-2">
            <Button variant="ghost" size="sm" as-child>
                <Link :href="IndexController.url()">
                    <ArrowLeft class="size-3.5" />
                    Back to Challenges
                </Link>
            </Button>
            <Button v-if="fromDeck !== null" variant="ghost" size="sm" as-child>
                <Link :href="DashboardController.url({ deck: fromDeck })">
                    <ArrowLeft class="size-3.5" />
                    Back to Deck
                </Link>
            </Button>
        </div>

        <!-- 3-column layout -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[240px_1fr_280px]">
            <!-- Left: Challenge details -->
            <Card>
                <CardContent class="flex flex-col gap-3 p-4">
                    <div>
                        <h1 class="text-base font-semibold leading-tight">
                            {{ challenge.name ?? 'Challenge' }}
                        </h1>
                        <p v-if="challenge.format" class="mt-0.5 text-sm text-zinc-400">{{ challenge.format }}</p>
                    </div>

                    <!-- Status badge -->
                    <span
                        class="inline-flex w-fit items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="stateBadgeClass(challenge.state)"
                    >
                        {{ stateLabel(challenge.state) }}
                    </span>

                    <div class="flex flex-col gap-2 text-sm">
                        <!-- Structure -->
                        <div v-if="challenge.tournament_structure" class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Structure</span>
                            <span class="text-zinc-200">{{ challenge.tournament_structure }}</span>
                        </div>

                        <!-- Round progress -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Round</span>
                            <span class="tabular-nums text-zinc-200">
                                <template v-if="challenge.current_round !== null && challenge.max_rounds !== null">
                                    {{ challenge.current_round }} / {{ challenge.max_rounds }}
                                </template>
                                <template v-else-if="challenge.current_round !== null">
                                    {{ challenge.current_round }}
                                </template>
                                <template v-else>—</template>
                            </span>
                        </div>

                        <!-- Players -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Players</span>
                            <span class="tabular-nums text-zinc-200">
                                <template v-if="challenge.max_players !== null">
                                    {{ challenge.player_count }} / {{ challenge.max_players }}
                                </template>
                                <template v-else>{{ challenge.player_count }}</template>
                                <template v-if="challenge.min_players !== null">
                                    <span class="text-zinc-500"> (min {{ challenge.min_players }})</span>
                                </template>
                            </span>
                        </div>

                        <!-- Started -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Started</span>
                            <span class="text-zinc-200" :title="formatTime(challenge.started_at)">
                                {{ relativeTime(challenge.started_at) }}
                            </span>
                        </div>

                        <!-- Ended -->
                        <div v-if="challenge.ended_at" class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Ended</span>
                            <span class="text-zinc-200" :title="formatTime(challenge.ended_at)">
                                {{ relativeTime(challenge.ended_at) }}
                            </span>
                        </div>

                        <!-- Participation -->
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Participation</span>
                            <span v-if="challenge.participated" class="text-green-400">Participated</span>
                            <span v-else class="text-zinc-500">Not participated</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Middle: Standings table -->
            <Card class="overflow-hidden">
                <div class="max-h-[600px] overflow-y-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
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
                                    <TableCell colspan="6" class="py-12 text-center text-sm text-zinc-500">
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
                                    {{ aggregateRecord(standing.match_record) }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                    {{ formatPct(standing.opponent_match_win_pct) }}
                                </TableCell>
                                <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                    {{ formatPct(standing.game_win_pct) }}
                                </TableCell>
                            </TableRow>

                            <!-- Pinned local standing when outside top 15 -->
                            <template v-if="showPinnedLocal && localStanding">
                                <TableRow class="border-t border-dashed border-zinc-700">
                                    <TableCell colspan="6" class="py-0 px-4 text-xs text-zinc-500 italic">
                                        Your position
                                    </TableCell>
                                </TableRow>
                                <TableRow class="bg-blue-500/10">
                                    <TableCell class="tabular-nums text-sm text-zinc-400">
                                        {{ localStanding.rank }}
                                    </TableCell>
                                    <TableCell class="text-sm font-medium text-blue-400">
                                        {{ localStanding.username ?? `#${localStanding.login_id}` }}
                                    </TableCell>
                                    <TableCell class="tabular-nums text-sm text-right">
                                        {{ localStanding.points }}
                                    </TableCell>
                                    <TableCell class="tabular-nums text-sm text-right">
                                        {{ aggregateRecord(localStanding.match_record) }}
                                    </TableCell>
                                    <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                        {{ formatPct(localStanding.opponent_match_win_pct) }}
                                    </TableCell>
                                    <TableCell class="tabular-nums text-sm text-right text-zinc-400">
                                        {{ formatPct(localStanding.game_win_pct) }}
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                </div>
            </Card>

            <!-- Right: Timeline feed -->
            <Card>
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
