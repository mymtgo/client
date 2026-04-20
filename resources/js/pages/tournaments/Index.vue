<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Tournaments/ShowController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link, router } from '@inertiajs/vue3';
import { Medal, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Tournament = {
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
    scheduled_at: string | null;
    started_at: string | null;
    participated: boolean;
};

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    tournaments: {
        data: Tournament[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    localStandings: Record<number, { tournament_id: number; rank: number; round: number }>;
    allFormats: string[];
    allCategories: string[];
    types: string[];
    eliminatedIds: number[];
    filters: {
        format: string;
        state: string;
        participated: boolean;
        search: string;
        type: string;
        category: string;
    };
}>();

const activeFormat = ref(props.filters.format || '');
const activeState = ref(props.filters.state || 'active');
const activeType = ref(props.filters.type || '');
const activeCategory = ref(props.filters.category || '');
const showParticipated = ref(props.filters.participated);
const searchQuery = ref(props.filters.search || '');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

function navigate(overrides: Record<string, unknown> = {}) {
    router.get(
        '/tournaments',
        {
            format: activeFormat.value || undefined,
            state: activeState.value,
            type: activeType.value || undefined,
            category: activeCategory.value || undefined,
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

function setType(value: string) {
    activeType.value = value === 'all' ? '' : value;
    navigate();
}

function setCategory(value: string) {
    activeCategory.value = value === 'all' ? '' : value;
    navigate();
}

function setParticipated(value: boolean) {
    showParticipated.value = value;
    navigate();
}

watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => navigate(), 300);
});

const eliminatedSet = computed(() => new Set(props.eliminatedIds));

function statusBadge(tournament: Tournament): { label: string; classes: string } {
    const base = 'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium';

    if (tournament.state === 'completed' && eliminatedSet.value.has(tournament.id)) {
        return {
            label: 'Eliminated',
            classes: `${base} border-red-400 bg-red-300/10 text-red-400`,
        };
    }

    if (tournament.state === 'completed') {
        return {
            label: 'Completed',
            classes: `${base} border-blue-400 bg-blue-300/10 text-blue-400`,
        };
    }

    return {
        label: 'In Progress',
        classes: `${base} border-blue-400 bg-blue-300/10 text-blue-400`,
    };
}

function relativeTime(dateStr: string | null): string {
    if (!dateStr) return '-';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function formatScheduled(dateStr: string): string {
    const date = new Date(dateStr);
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-3">
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

            <!-- Type dropdown -->
            <Select :model-value="activeType || 'all'" @update:model-value="setType">
                <SelectTrigger class="w-36">
                    <SelectValue placeholder="All Types" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Types</SelectItem>
                    <SelectItem v-for="t in types" :key="t" :value="t">{{ t }}</SelectItem>
                </SelectContent>
            </Select>

            <!-- Category dropdown -->
            <Select :model-value="activeCategory || 'all'" @update:model-value="setCategory">
                <SelectTrigger class="w-40">
                    <SelectValue placeholder="All Categories" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Categories</SelectItem>
                    <SelectItem v-for="c in allCategories" :key="c" :value="c">{{ c }}</SelectItem>
                </SelectContent>
            </Select>

            <!-- State dropdown -->
            <Select :model-value="activeState" @update:model-value="setState">
                <SelectTrigger class="w-36">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="completed">Completed</SelectItem>
                    <SelectItem value="all">All</SelectItem>
                </SelectContent>
            </Select>

            <!-- Participated toggle -->
            <div class="flex items-center gap-2">
                <Switch
                    :modelValue="showParticipated"
                    @update:modelValue="setParticipated"
                />
                <Label class="text-sm text-muted-foreground">Only participating</Label>
            </div>

            <!-- Search -->
            <div class="relative ml-auto">
                <Search class="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="searchQuery"
                    placeholder="Search tournaments..."
                    class="h-8 w-52 pl-8 text-sm"
                />
            </div>
        </div>

        <!-- Tournaments table -->
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
                        <TableHead>Time</TableHead>
                        <TableHead>Your Rank</TableHead>
                        <TableHead class="w-0"></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="tournaments.data.length === 0">
                        <TableRow>
                            <TableCell colspan="10" class="py-12 text-center">
                                <div class="flex flex-col items-center gap-2">
                                    <Medal class="size-10 text-zinc-600" />
                                    <p class="font-medium">No tournaments found</p>
                                    <p class="text-sm text-muted-foreground">Tournaments will appear here once MTGO data has been ingested.</p>
                                </div>
                            </TableCell>
                        </TableRow>
                    </template>
                    <TableRow v-for="tournament in tournaments.data" :key="tournament.id">
                        <TableCell>
                            <span :class="statusBadge(tournament).classes">
                                {{ statusBadge(tournament).label }}
                            </span>
                        </TableCell>
                        <TableCell class="font-medium">
                            {{ tournament.name ?? '-' }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ tournament.format ?? '-' }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ tournament.category ?? '-' }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground capitalize">
                            {{ tournament.tournament_structure ?? '-' }}
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">
                            <template v-if="tournament.current_round && tournament.max_rounds">
                                {{ tournament.current_round }}/{{ tournament.max_rounds }}
                            </template>
                            <template v-else-if="tournament.current_round">
                                {{ tournament.current_round }}
                            </template>
                            <template v-else>
                                <span class="text-muted-foreground">-</span>
                            </template>
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">
                            <template v-if="tournament.max_players !== null">
                                {{ tournament.player_count }}/{{ tournament.max_players }}
                            </template>
                            <template v-else>
                                {{ tournament.player_count }}
                            </template>
                        </TableCell>
                        <TableCell class="whitespace-nowrap text-sm text-muted-foreground">
                            <template v-if="tournament.started_at">
                                {{ relativeTime(tournament.started_at) }}
                            </template>
                            <template v-else-if="tournament.scheduled_at">
                                {{ formatScheduled(tournament.scheduled_at) }}
                            </template>
                            <template v-else>-</template>
                        </TableCell>
                        <TableCell class="tabular-nums text-sm">
                            <template v-if="localStandings[tournament.id]">
                                #{{ localStandings[tournament.id].rank }}
                            </template>
                            <template v-else>
                                <span class="text-muted-foreground">-</span>
                            </template>
                        </TableCell>
                        <TableCell>
                            <Link
                                :href="ShowController.url({ tournament: tournament.id })"
                                class="text-sm text-primary hover:underline"
                            >
                                View
                            </Link>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <!-- Pagination -->
            <div v-if="tournaments.last_page > 1" class="flex justify-end gap-1 px-2 py-2">
                <template v-for="link in tournaments.links" :key="link.label">
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
