<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Challenges/ShowController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link, router } from '@inertiajs/vue3';
import { Medal, Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';

type Challenge = {
    id: number;
    name: string | null;
    format: string | null;
    category: string | null;
    tournament_structure: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    player_count: number;
    max_players: number | null;
    started_at: string | null;
    participated: boolean;
};

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    challenges: {
        data: Challenge[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    localStandings: Record<number, { challenge_id: number; rank: number; round: number }>;
    allFormats: string[];
    filters: {
        format: string;
        state: string;
        participated: boolean;
        search: string;
    };
}>();

const activeFormat = ref(props.filters.format || '');
const activeState = ref(props.filters.state || 'active');
const showParticipated = ref(props.filters.participated);
const searchQuery = ref(props.filters.search || '');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

function navigate(overrides: Record<string, unknown> = {}) {
    router.get(
        '/challenges',
        {
            format: activeFormat.value || undefined,
            state: activeState.value !== 'all' ? activeState.value : undefined,
            participated: showParticipated.value || undefined,
            search: searchQuery.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function setFormat(value: string) {
    activeFormat.value = value === 'all' ? '' : value;
    navigate();
}

function setState(s: string) {
    activeState.value = s;
    navigate();
}

function toggleParticipated() {
    showParticipated.value = !showParticipated.value;
    navigate();
}

watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => navigate(), 300);
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

function relativeTime(dateStr: string | null): string {
    if (!dateStr) return '—';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Format dropdown -->
            <Select :model-value="activeFormat || 'all'" @update:model-value="setFormat">
                <SelectTrigger class="w-40">
                    <SelectValue placeholder="All Formats" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Formats</SelectItem>
                    <SelectItem v-for="f in allFormats" :key="f" :value="f">{{ f }}</SelectItem>
                </SelectContent>
            </Select>

            <!-- State toggle -->
            <div class="flex items-center gap-1">
                <Button size="sm" :variant="activeState === 'active' ? 'default' : 'outline'" @click="setState('active')">Active</Button>
                <Button size="sm" :variant="activeState === 'completed' ? 'default' : 'outline'" @click="setState('completed')">Completed</Button>
                <Button size="sm" :variant="activeState === 'all' ? 'default' : 'outline'" @click="setState('all')">All</Button>
            </div>

            <!-- Participated toggle -->
            <Button size="sm" :variant="showParticipated ? 'default' : 'outline'" @click="toggleParticipated">
                <Medal class="size-3.5" />
                My Challenges
            </Button>

            <!-- Search -->
            <div class="relative ml-auto">
                <Search class="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="searchQuery"
                    placeholder="Search challenges..."
                    class="h-8 w-52 pl-8 text-sm"
                />
            </div>
        </div>

        <!-- Challenges table -->
        <Card class="overflow-hidden">
            <Table>
                <TableHeader class="sticky top-0 z-10 backdrop-blur-sm">
                    <TableRow>
                        <TableHead>Status</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Format</TableHead>
                        <TableHead>Category</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Round</TableHead>
                        <TableHead>Players</TableHead>
                        <TableHead>Started</TableHead>
                        <TableHead>Your Rank</TableHead>
                        <TableHead class="w-0"></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="challenges.data.length === 0">
                        <TableRow>
                            <TableCell colspan="10" class="py-12 text-center">
                                <div class="flex flex-col items-center gap-2">
                                    <Medal class="size-10 text-muted-foreground/40" />
                                    <p class="font-medium">No challenges found</p>
                                    <p class="text-sm text-muted-foreground">Challenges will appear here once MTGO data has been ingested.</p>
                                </div>
                            </TableCell>
                        </TableRow>
                    </template>
                    <TableRow v-for="challenge in challenges.data" :key="challenge.id">
                        <TableCell>
                            <span class="text-sm font-medium" :class="stateColor(challenge.state)">
                                {{ stateLabel(challenge.state) }}
                            </span>
                        </TableCell>
                        <TableCell class="font-medium">
                            {{ challenge.name ?? '—' }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ challenge.format ?? '—' }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ challenge.category ?? '—' }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground capitalize">
                            {{ challenge.tournament_structure ?? '—' }}
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">
                            <template v-if="challenge.current_round !== null && challenge.max_rounds !== null">
                                {{ challenge.current_round }}/{{ challenge.max_rounds }}
                            </template>
                            <template v-else>—</template>
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">
                            <template v-if="challenge.max_players !== null">
                                {{ challenge.player_count }}/{{ challenge.max_players }}
                            </template>
                            <template v-else>
                                {{ challenge.player_count }}
                            </template>
                        </TableCell>
                        <TableCell class="whitespace-nowrap text-sm text-muted-foreground">
                            {{ relativeTime(challenge.started_at) }}
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">
                            <template v-if="localStandings[challenge.id]">
                                #{{ localStandings[challenge.id].rank }}
                            </template>
                            <template v-else>
                                <span class="text-muted-foreground">—</span>
                            </template>
                        </TableCell>
                        <TableCell>
                            <Link
                                :href="ShowController.url({ challenge: challenge.id })"
                                class="text-sm text-primary hover:underline"
                            >
                                View
                            </Link>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <!-- Pagination -->
            <div v-if="challenges.last_page > 1" class="flex justify-end gap-1 px-2 py-2">
                <template v-for="link in challenges.links" :key="link.label">
                    <Button
                        v-if="link.url"
                        size="sm"
                        :variant="link.active ? 'default' : 'outline'"
                        @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                        v-html="link.label"
                    />
                </template>
            </div>
        </Card>
    </div>
</template>
