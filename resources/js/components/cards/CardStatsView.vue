<script setup lang="ts">
import { ContextMenu, ContextMenuContent, ContextMenuTrigger } from '@/components/ui/context-menu';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import SegmentedControl from '@/components/SegmentedControl.vue';
import DeckCardStatsRow from '@/pages/decks/partials/DeckCardStatsRow.vue';
import { useShrinkage, type ShrunkStat } from '@/composables/useShrinkage';
import type { ShrinkKey } from '@/lib/stats/shrinkage';
import {
    CARD_STATS_COLUMNS,
    LOCAL_ONLY_COLUMNS,
    loadCardStatsVisibility,
    saveCardStatsVisibility,
    type CardStatsColumnKey,
    type CardStatsPerspective,
    type CardStatsVisibility,
} from '@/pages/decks/partials/cardStatsColumns';
import type { DeckCardStat } from '@/types/decks';
import type { ReportArchetypeOption } from '@/types/reports';
import {
    BarChart3,
    Check,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Columns3,
    Filter,
    Flame,
    Gem,
    HandFist,
    Lock,
    MountainSnow,
    Origami,
    PanelRightOpen,
    ScrollText,
    Search,
    Zap,
} from 'lucide-vue-next';
import { computed, ref, watch, type Component } from 'vue';
import { router } from '@inertiajs/vue3';

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
    filterChange: [params: {
        archetype?: string;
        playDraw?: string;
        board?: string;
        perspective?: string;
    }];
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

watch(() => props.perspective, (val) => {
    selectedPerspective.value = val;
});

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

type FilterKey = 'Creature' | 'Instant' | 'Sorcery' | 'Land' | 'Artifact' | 'Enchantment' | 'Planeswalker' | 'Sideboard';

const FILTER_CONFIG: { key: FilterKey; label: string; icon: Component }[] = [
    { key: 'Creature', label: 'Creatures', icon: Origami },
    { key: 'Instant', label: 'Instants', icon: Zap },
    { key: 'Sorcery', label: 'Sorceries', icon: Flame },
    { key: 'Enchantment', label: 'Enchantments', icon: ScrollText },
    { key: 'Artifact', label: 'Artifacts', icon: Gem },
    { key: 'Land', label: 'Lands', icon: MountainSnow },
    { key: 'Planeswalker', label: 'Planeswalkers', icon: HandFist },
    { key: 'Sideboard', label: 'Sideboard', icon: PanelRightOpen },
];

const STORAGE_KEY = 'cardStatsTypeFilters';

const ALL_ENABLED = Object.fromEntries(FILTER_CONFIG.map((f) => [f.key, true])) as Record<FilterKey, boolean>;

function loadFilters(): Record<FilterKey, boolean> {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            const hasAllKeys = FILTER_CONFIG.every((f) => f.key in parsed);
            if (hasAllKeys) return parsed;
        }
    } catch {}
    return { ...ALL_ENABLED };
}

function saveFilters(filters: Record<FilterKey, boolean>) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(filters));
}

const typeFilters = ref<Record<FilterKey, boolean>>(loadFilters());

function setFilter(key: FilterKey, value: boolean) {
    typeFilters.value[key] = value;
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
    for (const filter of FILTER_CONFIG) {
        if (!present.has(filter.key) && !typeFilters.value[filter.key]) {
            typeFilters.value[filter.key] = true;
            changed = true;
        }
    }
    if (changed) saveFilters(typeFilters.value);
});

const visibleFilters = computed(() => FILTER_CONFIG.filter((f) => presentTypes.value.has(f.key)));

const activeFilterCount = computed(() => visibleFilters.value.filter((f) => !typeFilters.value[f.key]).length);

const allVisible = computed(() => visibleFilters.value.every((f) => typeFilters.value[f.key]));

function toggleAll() {
    const newVal = !allVisible.value;
    for (const filter of visibleFilters.value) {
        typeFilters.value[filter.key] = newVal;
    }
    saveFilters(typeFilters.value);
}

function normalizeType(raw: string | null): string {
    if (!raw) return 'Other';
    const canonical: FilterKey[] = ['Creature', 'Planeswalker', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'];
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
    props.perspective === 'theirs'
        ? CARD_STATS_COLUMNS.filter((c) => !LOCAL_ONLY_COLUMNS.includes(c.key))
        : CARD_STATS_COLUMNS,
);

const allColumnsVisible = computed(() => configurableColumns.value.every((c) => visibleColumns.value[c.key]));

function toggleAllColumns(): void {
    const newVal = !allColumnsVisible.value;
    for (const col of configurableColumns.value) {
        visibleColumns.value[col.key] = newVal;
    }
    saveCardStatsVisibility(visibleColumns.value);
}

const visibleColumnCount = computed(
    () => 1 + configurableColumns.value.filter((c) => effectiveVisibleColumns.value[c.key]).length,
);

const hiddenColumnCount = computed(() => configurableColumns.value.length - (visibleColumnCount.value - 1));

// ── Sorting ──────────────────────────────────────────────────────────────────

type SortKey = 'name' | 'keptPct' | 'keptWinPct' | 'seenPct' | 'seenWinPct' | 'castPct' | 'castWinPct' | 'playedPct' | 'kicked' | 'activated' | 'pregamePct' | 'pregameWinPct' | 'sbOutPct' | 'sbInPct' | 'games';
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

    switch (key) {
        case 'name': return stat.name;
        case 'keptPct': return rateOrNeg(stat.keptGames, stat.totalGames);
        case 'seenPct': return rateOrNeg(stat.seenGames, stat.totalGames);
        case 'castPct': return rateOrNeg(stat.castGames, stat.totalGames);
        case 'playedPct': return rateOrNeg(stat.playedGames, stat.totalGames);
        case 'kicked': return stat.totalKicked;
        case 'activated': return stat.totalActivated;
        case 'pregamePct': return rateOrNeg(stat.pregameGames, stat.totalGames);
        case 'sbOutPct': return rateOrNeg(stat.sidedOutGames, stat.postboardGames);
        case 'sbInPct': return rateOrNeg(stat.sidedInGames, stat.postboardGames);
        case 'games': return stat.totalGames;
        default: return 0;
    }
}

const filteredAndSortedStats = computed<ShrunkStat<DeckCardStat>[]>(() => {
    const q = searchQuery.value.toLowerCase();
    const filtered = shrunkStats.value.filter(
        (entry) => passesFilter(entry.raw) && (!q || entry.raw.name.toLowerCase().includes(q)),
    );

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
    <div v-if="archetypes.length || stats.length" class="mb-4 flex flex-col gap-4">
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

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" class="bevel py-2 gap-1.5 rounded-md border border-black/60 px-3 text-xs">
                            <Filter class="size-3.5" />
                            <span v-if="activeFilterCount > 0">{{ activeFilterCount }} hidden</span>
                            <span v-else>Card types</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <div class="flex items-center justify-between px-2 py-1.5">
                            <span class="text-xs font-semibold">Filter by type</span>
                            <button class="text-xs text-muted-foreground hover:text-foreground" @click="toggleAll">
                                {{ allVisible ? 'Hide all' : 'Show all' }}
                            </button>
                        </div>
                        <DropdownMenuSeparator />
                        <template v-for="filter in visibleFilters" :key="filter.key">
                            <DropdownMenuSeparator v-if="filter.key === 'Sideboard'" />
                            <DropdownMenuCheckboxItem
                                :modelValue="typeFilters[filter.key]"
                                @update:modelValue="(val: boolean) => setFilter(filter.key, val)"
                                @select.prevent
                            >
                                <template #indicator-icon>
                                    <Check class="size-4 text-success" />
                                </template>
                                <component :is="filter.icon" class="mr-2 size-3.5 text-muted-foreground" />
                                {{ filter.label }}
                            </DropdownMenuCheckboxItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" class="bevel py-2 gap-1.5 rounded-md border border-black/60 px-3 text-xs">
                            <Columns3 class="size-3.5" />
                            <span v-if="hiddenColumnCount > 0">{{ hiddenColumnCount }} hidden</span>
                            <span v-else>Columns</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
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
                        <DropdownMenuCheckboxItem
                            v-for="col in configurableColumns"
                            :key="col.key"
                            :modelValue="visibleColumns[col.key]"
                            @update:modelValue="(val: boolean) => setColumnVisible(col.key, val)"
                            @select.prevent
                        >
                            <template #indicator-icon>
                                <Check class="size-4 text-success" />
                            </template>
                            {{ col.label }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>

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
        <p class="max-w-sm text-sm text-muted-foreground">
            Check back as more matches are reported. Community stats are refreshed daily.
        </p>
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

    <Card v-else class="gap-0 p-0">
        <CardContent class="px-0 [&_[data-slot=table-container]]:overflow-visible">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                            <span class="inline-flex items-center gap-1">Card <component :is="sortIcon('name')" class="size-3" /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.type">Type</TableHead>
                        <TableHead v-if="effectiveVisibleColumns.sb" class="w-10 text-center">SB</TableHead>
                        <TableHead v-if="effectiveVisibleColumns.keptPct" class="cursor-pointer text-right select-none" @click="toggleSort('keptPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Kept % <component :is="sortIcon('keptPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.keptWinPct" class="cursor-pointer text-right select-none" @click="toggleSort('keptWinPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Kept Win % <component :is="sortIcon('keptWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.castPct" class="cursor-pointer text-right select-none" @click="toggleSort('castPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Cast % <component :is="sortIcon('castPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.castWinPct" class="cursor-pointer text-right select-none" @click="toggleSort('castWinPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Cast Win % <component :is="sortIcon('castWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.playedPct" class="cursor-pointer text-right select-none" @click="toggleSort('playedPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Played % <component :is="sortIcon('playedPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.kicked" class="cursor-pointer text-right select-none" @click="toggleSort('kicked')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Kicked <component :is="sortIcon('kicked')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.activated" class="cursor-pointer text-right select-none" @click="toggleSort('activated')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Activated <component :is="sortIcon('activated')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.pregamePct" class="cursor-pointer text-right select-none" @click="toggleSort('pregamePct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Pregame % <component :is="sortIcon('pregamePct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.pregameWinPct" class="cursor-pointer text-right select-none" @click="toggleSort('pregameWinPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Pregame Win % <component :is="sortIcon('pregameWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.seenPct" class="cursor-pointer text-right select-none" @click="toggleSort('seenPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Seen % <component :is="sortIcon('seenPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.seenWinPct" class="cursor-pointer text-right select-none" @click="toggleSort('seenWinPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Seen Win % <component :is="sortIcon('seenWinPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.sbOutPct" class="cursor-pointer text-right select-none" @click="toggleSort('sbOutPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >SB Out % <component :is="sortIcon('sbOutPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.sbInPct" class="cursor-pointer text-right select-none" @click="toggleSort('sbInPct')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >SB In % <component :is="sortIcon('sbInPct')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead v-if="effectiveVisibleColumns.games" class="cursor-pointer text-right select-none" @click="toggleSort('games')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Games <component :is="sortIcon('games')" class="size-3"
                            /></span>
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

    <Teleport to="body">
        <img
            v-if="hoveredImage"
            :src="hoveredImage"
            class="pointer-events-none fixed z-50 w-56 rounded-lg shadow-xl"
            :style="{ top: `${mouseY - 160}px`, left: `${mouseX + 16}px` }"
        />
    </Teleport>
</template>
