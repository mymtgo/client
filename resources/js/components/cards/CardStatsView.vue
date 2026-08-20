<script setup lang="ts">
import SegmentedControl from '@/components/SegmentedControl.vue';
import CardTypeFilter from '@/components/cards/CardTypeFilter.vue';
import { CARD_TYPE_KEYS, type CardTypeKey } from '@/components/cards/cardTypes';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ContextMenu, ContextMenuContent, ContextMenuTrigger } from '@/components/ui/context-menu';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useShrinkage, type ShrunkStat } from '@/composables/useShrinkage';
import type { ShrinkKey } from '@/lib/stats/shrinkage';
import DeckCardStatsGridCard from '@/pages/decks/partials/DeckCardStatsGridCard.vue';
import DeckCardStatsRow from '@/pages/decks/partials/DeckCardStatsRow.vue';
import {
    CARD_STATS_COLUMNS,
    CARD_STATS_COLUMN_GROUPS,
    CASTING_METHOD_COLUMNS,
    LOCAL_ONLY_COLUMNS,
    loadCardStatsVisibility,
    saveCardStatsVisibility,
    type CardStatsColumnKey,
    type CardStatsPerspective,
    type CardStatsVisibility,
    type CastingMethodColumnKey,
} from '@/pages/decks/partials/cardStatsColumns';
import type { DeckCardStat } from '@/types/decks';
import type { ReportArchetypeOption } from '@/types/reports';
import { router } from '@inertiajs/vue3';
import {
    ArrowDownUp,
    BarChart3,
    Check,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Columns3,
    Filter,
    LayoutGrid,
    List,
    Lock,
    Search,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    stats: DeckCardStat[];
    archetypes: ReportArchetypeOption[];
    perspective: CardStatsPerspective;
    deckWinrateRate: number;
    trustValue: number;
    source?: 'local' | 'external';
    loading?: boolean;
    /**
     * Keys that are currently active in the filter query, used to initialise
     * the filter controls.  The host reads these from query-string props.
     */
    currentArchetype?: string | null;
    currentPlayDraw?: string | null;
    currentBoard?: string | null;
}>();

const emit = defineEmits<{
    /**
     * Fired when the user changes a filter.  The host is responsible for
     * navigating / reloading with the new params.
     */
    filterChange: [
        params: {
            archetype?: string;
            playDraw?: string;
            board?: string;
            perspective?: string;
        },
    ];
}>();

// ── Filter state ─────────────────────────────────────────────────────────────

const selectedArchetype = ref<string>(props.currentArchetype ?? '__all__');
const selectedPlayDraw = ref<string>(props.currentPlayDraw ?? '__all__');
const selectedBoard = ref<string>(props.currentBoard ?? '__all__');
const selectedPerspective = ref<CardStatsPerspective>(props.perspective);
const searchQuery = ref('');

// ── Trust + shrinkage ────────────────────────────────────────────────────────

const isExternal = computed<boolean>(() => props.source === 'external');

function useMine(): void {
    router.reload({
        data: { card_stats_source: undefined },
        only: ['cardStats'],
        preserveScroll: true,
        preserveState: true,
    });
}

const shrunkStats = useShrinkage({
    stats: () => props.stats,
    prior: () => props.deckWinrateRate,
    strength: () => props.trustValue,
    perspective: () => props.perspective ?? 'mine',
});

watch(
    () => props.perspective,
    (val) => {
        selectedPerspective.value = val;
    },
);

function buildParams() {
    return {
        archetype: selectedArchetype.value === '__all__' ? undefined : selectedArchetype.value,
        playDraw: selectedPlayDraw.value === '__all__' ? undefined : selectedPlayDraw.value,
        board: selectedBoard.value === '__all__' ? undefined : selectedBoard.value,
        perspective: selectedPerspective.value === 'theirs' ? 'theirs' : undefined,
    };
}

function filterByArchetype(value: string) {
    selectedArchetype.value = value;
    emit('filterChange', buildParams());
}

function filterByPlayDraw(value: string) {
    selectedPlayDraw.value = value;
    emit('filterChange', buildParams());
}

function filterByBoard(value: string) {
    selectedBoard.value = value;
    emit('filterChange', buildParams());
}

function filterByPerspective(value: string) {
    selectedPerspective.value = value === 'theirs' ? 'theirs' : 'mine';
    emit('filterChange', buildParams());
}

// ── Type filter ──────────────────────────────────────────────────────────────

type FilterKey = CardTypeKey;

const FILTER_KEYS: FilterKey[] = [...CARD_TYPE_KEYS, 'Sideboard'];

const STORAGE_KEY = 'cardStatsTypeFilters';

const ALL_ENABLED = Object.fromEntries(FILTER_KEYS.map((key) => [key, true])) as Record<FilterKey, boolean>;

function loadFilters(): Record<FilterKey, boolean> {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            const hasAllKeys = FILTER_KEYS.every((key) => key in parsed);
            if (hasAllKeys) return parsed;
        }
    } catch {}
    return { ...ALL_ENABLED };
}

function saveFilters(filters: Record<FilterKey, boolean>) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(filters));
}

const typeFilters = ref<Record<FilterKey, boolean>>(loadFilters());

function onTypeFiltersUpdate(value: Partial<Record<FilterKey, boolean>>) {
    typeFilters.value = { ...typeFilters.value, ...value };
    saveFilters(typeFilters.value);
}

const presentTypes = computed(() => {
    const types = new Set<FilterKey>();
    for (const stat of props.stats) {
        if (stat.isSideboard) types.add('Sideboard');
        const type = normalizeType(stat.type);
        if (type !== 'Other') types.add(type as FilterKey);
    }
    return types;
});

watch(presentTypes, (present) => {
    let changed = false;
    for (const key of FILTER_KEYS) {
        if (!present.has(key) && !typeFilters.value[key]) {
            typeFilters.value[key] = true;
            changed = true;
        }
    }
    if (changed) saveFilters(typeFilters.value);
});

const visibleFilters = computed(() => FILTER_KEYS.filter((key) => presentTypes.value.has(key)));

function normalizeType(raw: string | null): string {
    if (!raw) return 'Other';
    const canonical: FilterKey[] = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'];
    for (const type of canonical) {
        if (raw.includes(type)) return type;
    }
    return 'Other';
}

function passesFilter(stat: DeckCardStat): boolean {
    const type = normalizeType(stat.type);

    if (stat.isSideboard) {
        return typeFilters.value.Sideboard;
    }

    if (type !== 'Other' && !typeFilters.value[type as FilterKey]) return false;

    return true;
}

// ── Column visibility ───────────────────────────────────────────────────────

const visibleColumns = ref<CardStatsVisibility>(loadCardStatsVisibility());

function setColumnVisible(key: CardStatsColumnKey, value: boolean): void {
    visibleColumns.value[key] = value;
    saveCardStatsVisibility(visibleColumns.value);
}

const effectiveVisibleColumns = computed<CardStatsVisibility>(() => {
    if (props.perspective !== 'theirs') return visibleColumns.value;
    const overridden = { ...visibleColumns.value };
    for (const key of LOCAL_ONLY_COLUMNS) {
        overridden[key] = false;
    }
    return overridden;
});

const configurableColumns = computed(() =>
    props.perspective === 'theirs' ? CARD_STATS_COLUMNS.filter((c) => !LOCAL_ONLY_COLUMNS.includes(c.key)) : CARD_STATS_COLUMNS,
);

// Grouped + alphabetical for the picker only — the table keeps its logical column order.
const pickerGroups = computed(() =>
    CARD_STATS_COLUMN_GROUPS.map((group) => ({
        label: group.label,
        columns: configurableColumns.value
            .filter((col) => group.keys.includes(col.key))
            .sort((a, b) => a.label.localeCompare(b.label)),
    })).filter((group) => group.columns.length > 0),
);

const allColumnsVisible = computed(() => configurableColumns.value.every((c) => visibleColumns.value[c.key]));

function toggleAllColumns(): void {
    const newVal = !allColumnsVisible.value;
    for (const col of configurableColumns.value) {
        visibleColumns.value[col.key] = newVal;
    }
    saveCardStatsVisibility(visibleColumns.value);
}

const visibleColumnCount = computed(() => 1 + configurableColumns.value.filter((c) => effectiveVisibleColumns.value[c.key]).length);

const hiddenColumnCount = computed(() => configurableColumns.value.length - (visibleColumnCount.value - 1));

// ── Sorting ──────────────────────────────────────────────────────────────────

type SortKey =
    | 'name'
    | 'keptPct'
    | 'keptWinPct'
    | 'seenPct'
    | 'seenWinPct'
    | 'castPct'
    | 'castWinPct'
    | 'playedPct'
    | 'kicked'
    | 'activated'
    | 'pregamePct'
    | 'pregameWinPct'
    | 'sbOutPct'
    | 'sbInPct'
    | 'games'
    | CastingMethodColumnKey;
const sortBy = ref<SortKey>('name');
const sortDesc = ref(false);

function toggleSort(key: SortKey) {
    if (sortBy.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortBy.value = key;
        sortDesc.value = key !== 'name';
    }
}

// ── View mode (table / grid) ──────────────────────────────────────────────────

type ViewMode = 'table' | 'grid';

const VIEW_MODE_STORAGE_KEY = 'cardStatsViewMode';

function loadViewMode(): ViewMode {
    try {
        const stored = localStorage.getItem(VIEW_MODE_STORAGE_KEY);
        if (stored === 'grid' || stored === 'table') return stored;
    } catch {}
    return 'table';
}

const viewMode = ref<ViewMode>(loadViewMode());

function setViewMode(mode: ViewMode): void {
    viewMode.value = mode;
    try {
        localStorage.setItem(VIEW_MODE_STORAGE_KEY, mode);
    } catch {}
}

// ── Grid sort control ─────────────────────────────────────────────────────────
//
// Grid tiles have no clickable headers, so a dropdown drives the same sort state
// the table headers use — the chosen sort survives switching views.

function sortLabel(key: SortKey): string {
    if (key === 'name') return 'Name';
    return CARD_STATS_COLUMNS.find((c) => c.key === key)?.label ?? 'Name';
}

const sortOptions = computed<{ key: SortKey; label: string }[]>(() => {
    const options: { key: SortKey; label: string }[] = [{ key: 'name', label: 'Name' }];
    for (const col of CARD_STATS_COLUMNS) {
        if (col.key === 'type' || col.key === 'sb') continue;
        if (!effectiveVisibleColumns.value[col.key]) continue;
        options.push({ key: col.key as SortKey, label: col.label });
    }
    return options;
});

const currentSortLabel = computed(() => sortLabel(sortBy.value));

function chooseSort(key: SortKey): void {
    if (sortBy.value === key) return;
    sortBy.value = key;
    sortDesc.value = key !== 'name';
}

function toggleSortDirection(): void {
    sortDesc.value = !sortDesc.value;
}

const SHRINK_KEY_BY_SORT: Partial<Record<SortKey, ShrinkKey>> = {
    keptWinPct: 'kept',
    castWinPct: 'cast',
    seenWinPct: 'seen',
    pregameWinPct: 'pregame',
};

function rateOrNeg(num: number, denom: number): number {
    return denom > 0 ? num / denom : -1;
}

function sortValue(entry: ShrunkStat<DeckCardStat>, key: SortKey): number | string {
    const stat = entry.raw;
    const shrinkKey = SHRINK_KEY_BY_SORT[key];
    if (shrinkKey) {
        // No samples means no data — sort below every card that has any.
        // Sort on the rounded display percentage (not the raw shrunk value) so
        // cards showing the same % tie and fall through to the sample-size
        // tiebreaker instead of ordering on invisible decimal places.
        return entry.samples[shrinkKey] > 0 ? Math.round(entry.shrunk[shrinkKey] * 100) : -1;
    }

    const castingColumn = CASTING_METHOD_COLUMNS.find((col) => col.key === key);
    if (castingColumn) {
        return stat[castingColumn.statField] as number;
    }

    switch (key) {
        case 'name':
            return stat.name;
        case 'keptPct':
            return rateOrNeg(stat.keptGames, stat.totalGames);
        case 'seenPct':
            return rateOrNeg(stat.seenGames, stat.totalGames);
        case 'castPct':
            return rateOrNeg(stat.castGames, stat.totalGames);
        case 'playedPct':
            return rateOrNeg(stat.playedGames, stat.totalGames);
        case 'kicked':
            return stat.totalKicked;
        case 'activated':
            return stat.totalActivated;
        case 'pregamePct':
            return rateOrNeg(stat.pregameGames, stat.totalGames);
        case 'sbOutPct':
            return rateOrNeg(stat.sidedOutGames, stat.postboardGames);
        case 'sbInPct':
            return rateOrNeg(stat.sidedInGames, stat.postboardGames);
        case 'games':
            return stat.totalGames;
        default:
            return 0;
    }
}

const filteredAndSortedStats = computed<ShrunkStat<DeckCardStat>[]>(() => {
    const q = searchQuery.value.toLowerCase();
    const filtered = shrunkStats.value.filter((entry) => passesFilter(entry.raw) && (!q || entry.raw.name.toLowerCase().includes(q)));

    const sortKey = sortBy.value;
    const desc = sortDesc.value;

    // Win-rate sorts tie-break on sample size so e.g. 82% over 78 games
    // ranks above 82% over 11 games.
    const tieShrinkKey = SHRINK_KEY_BY_SORT[sortKey];
    const decorated = filtered.map((entry) => ({
        entry,
        key: sortValue(entry, sortKey),
        tie: tieShrinkKey ? entry.samples[tieShrinkKey] : 0,
    }));
    decorated.sort((a, b) => {
        const cmp = a.key < b.key ? -1 : a.key > b.key ? 1 : a.tie - b.tie;
        return desc ? -cmp : cmp;
    });

    return decorated.map((d) => d.entry);
});

// ── Card image hover ────────────────────────────────────────────────────────

const hoveredImage = ref<string | null>(null);
const mouseX = ref(0);
const mouseY = ref(0);

function onRowEnter(stat: DeckCardStat) {
    if (stat.image) hoveredImage.value = stat.image;
}
function onRowMove(e: MouseEvent) {
    mouseX.value = e.clientX;
    mouseY.value = e.clientY;
}
function onRowLeave() {
    hoveredImage.value = null;
}

function sortIcon(key: SortKey) {
    if (sortBy.value !== key) return ChevronsUpDown;
    return sortDesc.value ? ChevronDown : ChevronUp;
}

// Expose state needed by the host for screenshot context menu
defineExpose({ selectedArchetype, selectedPlayDraw, selectedBoard, visibleColumns });
</script>

<template>
    <div v-if="archetypes.length || stats.length" class="flex shrink-0 flex-col gap-4">
        <div class="flex items-center gap-4">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="searchQuery" placeholder="Search cards..." class="pl-7 text-xs" />
            </div>

            <SegmentedControl
                v-if="source == 'local'"
                :modelValue="selectedPerspective"
                :options="[
                    { value: 'mine', label: 'My Cards' },
                    { value: 'theirs', label: 'Their Cards' },
                ]"
                @update:modelValue="filterByPerspective"
            />

            <SegmentedControl
                :modelValue="selectedBoard"
                :options="[
                    { value: '__all__', label: 'Overall' },
                    { value: 'preboard', label: 'Game 1' },
                    { value: 'postboard', label: 'Postboard' },
                ]"
                @update:modelValue="filterByBoard"
            />

            <div class="flex items-center gap-2">
                <Select :modelValue="selectedPlayDraw" @update:modelValue="(v) => filterByPlayDraw(String(v))">
                    <SelectTrigger>
                        <SelectValue placeholder="Play / Draw" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all__">Play & Draw</SelectItem>
                        <SelectItem value="play">On the Play</SelectItem>
                        <SelectItem value="draw">On the Draw</SelectItem>
                    </SelectContent>
                </Select>

                <Select v-if="archetypes.length" :modelValue="selectedArchetype" @update:modelValue="(v) => filterByArchetype(String(v))">
                    <SelectTrigger>
                        <SelectValue placeholder="All opponent archetypes" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all__">All opponent archetypes</SelectItem>
                        <SelectItem v-for="arch in archetypes" :key="arch.id" :value="String(arch.id)">
                            {{ arch.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <CardTypeFilter
                    :modelValue="typeFilters"
                    :keys="visibleFilters"
                    separator-before="Sideboard"
                    align="end"
                    trigger-class="bevel border-black/60 text-xs"
                    @update:modelValue="onTypeFiltersUpdate"
                />

                <div v-if="viewMode === 'grid'" class="flex items-center">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                class="bevel gap-1.5 rounded-l-md rounded-r-none border border-r-0 border-black/60 px-3 py-2 text-xs"
                            >
                                <ArrowDownUp class="size-3.5" />
                                <span>{{ currentSortLabel }}</span>
                                <ChevronDown class="size-3" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuLabel class="text-xs font-semibold">Sort by</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem v-for="option in sortOptions" :key="option.key" class="text-xs" @select="chooseSort(option.key)">
                                <Check v-if="sortBy === option.key" class="size-3.5 text-success" />
                                <span :class="sortBy === option.key ? '' : 'pl-[1.375rem]'">{{ option.label }}</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        variant="ghost"
                        class="bevel gap-1.5 rounded-l-none rounded-r-md border border-black/60 px-2 py-2 text-xs"
                        :title="sortDesc ? 'Descending' : 'Ascending'"
                        @click="toggleSortDirection"
                    >
                        <component :is="sortDesc ? ChevronDown : ChevronUp" class="size-3.5" />
                    </Button>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" class="bevel gap-1.5 rounded-md border border-black/60 px-3 py-2 text-xs">
                            <Columns3 class="size-3.5" />
                            <span v-if="hiddenColumnCount > 0">{{ hiddenColumnCount }} hidden</span>
                            <span v-else>Columns</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-[38rem]">
                        <div class="flex items-center justify-between px-2 py-1.5">
                            <span class="text-xs font-semibold">Visible columns</span>
                            <button class="text-xs text-muted-foreground hover:text-foreground" @click="toggleAllColumns">
                                {{ allColumnsVisible ? 'Hide all' : 'Show all' }}
                            </button>
                        </div>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel class="flex items-center gap-2 text-xs font-normal text-muted-foreground">
                            <Lock class="size-3" />
                            Card <span class="ml-auto text-[10px]">locked</span>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <template v-for="(group, index) in pickerGroups" :key="group.label">
                            <DropdownMenuSeparator v-if="index > 0" />
                            <DropdownMenuLabel class="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {{ group.label }}
                            </DropdownMenuLabel>
                            <div class="grid grid-cols-4 gap-x-1">
                                <DropdownMenuCheckboxItem
                                    v-for="col in group.columns"
                                    :key="col.key"
                                    :modelValue="visibleColumns[col.key]"
                                    class="whitespace-nowrap"
                                    @update:modelValue="(val: boolean) => setColumnVisible(col.key, val)"
                                    @select.prevent
                                >
                                    <template #indicator-icon>
                                        <Check class="size-4 text-success" />
                                    </template>
                                    {{ col.label }}
                                </DropdownMenuCheckboxItem>
                            </div>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>

                <div class="flex items-center">
                    <Button
                        variant="ghost"
                        class="bevel rounded-l-md rounded-r-none border border-r-0 border-black/60 px-2.5 py-2 text-xs"
                        :class="viewMode === 'table' ? 'text-foreground' : 'text-muted-foreground'"
                        :aria-pressed="viewMode === 'table'"
                        title="Table view"
                        @click="setViewMode('table')"
                    >
                        <List class="size-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        class="bevel rounded-l-none rounded-r-md border border-black/60 px-2.5 py-2 text-xs"
                        :class="viewMode === 'grid' ? 'text-foreground' : 'text-muted-foreground'"
                        :aria-pressed="viewMode === 'grid'"
                        title="Grid view"
                        @click="setViewMode('grid')"
                    >
                        <LayoutGrid class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <Card v-if="loading" class="gap-0 overflow-hidden p-0">
        <CardContent class="flex flex-col gap-2 px-4 py-4">
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-8 w-3/4" />
        </CardContent>
    </Card>

    <div v-else-if="isExternal && !stats.length" class="flex flex-col items-center gap-3 py-16 text-center">
        <BarChart3 class="size-10 text-muted-foreground/40" />
        <p class="font-medium">No community data yet for this archetype</p>
        <p class="max-w-sm text-sm text-muted-foreground">Check back as more matches are reported. Community stats are refreshed daily.</p>
        <Button variant="outline" size="sm" class="mt-2" @click="useMine">Use mine</Button>
    </div>

    <div v-else-if="!stats.length && perspective === 'theirs'" class="flex flex-col items-center gap-3 py-16 text-center">
        <BarChart3 class="size-10 text-muted-foreground/40" />
        <p class="font-medium">No opponent cards tracked yet</p>
        <p class="max-w-sm text-sm text-muted-foreground">
            Their cards will appear here as you play more games. Switch back to "My Cards" to see your own stats.
        </p>
    </div>

    <div v-else-if="!stats.length" class="flex flex-col items-center gap-3 py-16 text-center">
        <BarChart3 class="size-10 text-muted-foreground/40" />
        <p class="font-medium">No card stats yet</p>
        <p class="max-w-sm text-sm text-muted-foreground">
            Card performance stats will appear here once you've played some games. Stats may take a moment to compute after matches complete.
        </p>
    </div>

    <div v-else-if="!filteredAndSortedStats.length" class="flex flex-col items-center gap-3 py-16 text-center">
        <Filter class="size-10 text-muted-foreground/40" />
        <p class="font-medium">All card types are hidden</p>
        <p class="max-w-sm text-sm text-muted-foreground">Enable some card types in the filter to view stats.</p>
    </div>

    <Card v-else-if="viewMode === 'table'" class="min-h-0 flex-1 gap-0 p-0">
        <CardContent class="min-h-0 flex-1 px-0 [&_[data-slot=table-container]]:h-full [&_[data-slot=table-container]]:overflow-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                            <span class="inline-flex items-center gap-1">Card <component :is="sortIcon('name')" class="size-3" /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.type">Type</TableHead>
                        <TableHead v-if="effectiveVisibleColumns.sb" class="w-10 text-center">SB</TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.keptPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('keptPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Kept % <component :is="sortIcon('keptPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.keptWinPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('keptWinPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Kept Win % <component :is="sortIcon('keptWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.castPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('castPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Cast % <component :is="sortIcon('castPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.castWinPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('castWinPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Cast Win % <component :is="sortIcon('castWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.playedPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('playedPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Played % <component :is="sortIcon('playedPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.kicked" class="cursor-pointer text-right select-none" @click="toggleSort('kicked')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Kicked <component :is="sortIcon('kicked')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.activated"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('activated')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Activated <component :is="sortIcon('activated')" class="size-3"
                            /></span>
                        </TableHead>
                        <template v-for="column in CASTING_METHOD_COLUMNS" :key="column.key">
                            <TableHead
                                v-if="effectiveVisibleColumns[column.key]"
                                class="cursor-pointer text-right select-none"
                                @click="toggleSort(column.key)"
                            >
                                <span class="inline-flex items-center justify-end gap-1"
                                    >{{ column.label }} <component :is="sortIcon(column.key)" class="size-3"
                                /></span>
                            </TableHead>
                        </template>
                        <TableHead
                            v-if="effectiveVisibleColumns.pregamePct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('pregamePct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Pregame % <component :is="sortIcon('pregamePct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.pregameWinPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('pregameWinPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Pregame Win % <component :is="sortIcon('pregameWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.seenPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('seenPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Seen % <component :is="sortIcon('seenPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.seenWinPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('seenWinPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >Seen Win % <component :is="sortIcon('seenWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.sbOutPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('sbOutPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >SB Out % <component :is="sortIcon('sbOutPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead
                            v-if="effectiveVisibleColumns.sbInPct"
                            class="cursor-pointer text-right select-none"
                            @click="toggleSort('sbInPct')"
                        >
                            <span class="inline-flex items-center justify-end gap-1"
                                >SB In % <component :is="sortIcon('sbInPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.games" class="cursor-pointer text-right select-none" @click="toggleSort('games')">
                            <span class="inline-flex items-center justify-end gap-1">Games <component :is="sortIcon('games')" class="size-3" /></span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <ContextMenu v-for="entry in filteredAndSortedStats" :key="entry.raw.oracleId">
                        <ContextMenuTrigger asChild>
                            <DeckCardStatsRow
                                :stat="entry.raw"
                                :shrunk="entry.shrunk"
                                :raw-rates="entry.rawRates"
                                :samples="entry.samples"
                                :prior="deckWinrateRate"
                                :trust="trustValue"
                                :visible-columns="effectiveVisibleColumns"
                                :perspective="perspective"
                                @image-enter="onRowEnter"
                                @image-move="onRowMove"
                                @image-leave="onRowLeave"
                            />
                        </ContextMenuTrigger>
                        <ContextMenuContent>
                            <!-- Slot for host-specific row context menu items (e.g. screenshot) -->
                            <slot name="row-actions" :stat="entry.raw" />
                        </ContextMenuContent>
                    </ContextMenu>
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <div
        v-else
        class="grid min-h-0 flex-1 grid-cols-1 content-start gap-3 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
    >
        <ContextMenu v-for="entry in filteredAndSortedStats" :key="entry.raw.oracleId">
            <ContextMenuTrigger as-child>
                <DeckCardStatsGridCard
                    :stat="entry.raw"
                    :shrunk="entry.shrunk"
                    :raw-rates="entry.rawRates"
                    :samples="entry.samples"
                    :prior="deckWinrateRate"
                    :trust="trustValue"
                    :visible-columns="effectiveVisibleColumns"
                    :perspective="perspective"
                />
            </ContextMenuTrigger>
            <ContextMenuContent>
                <!-- Slot for host-specific row context menu items (e.g. screenshot) -->
                <slot name="row-actions" :stat="entry.raw" />
            </ContextMenuContent>
        </ContextMenu>
    </div>

    <Teleport to="body">
        <img
            v-if="hoveredImage"
            :src="hoveredImage"
            class="pointer-events-none fixed z-50 w-56 rounded-lg shadow-xl"
            :style="{ top: `${mouseY - 160}px`, left: `${mouseX + 16}px` }"
        />
    </Teleport>
</template>
