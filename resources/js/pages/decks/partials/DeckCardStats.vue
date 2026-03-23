<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Deferred, router } from '@inertiajs/vue3';
import {
    BarChart3,
    Check,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Filter,
    Flame,
    Gem,
    HandFist,
    MountainSnow,
    Origami,
    PanelRightOpen,
    ScrollText,
    Zap,
} from 'lucide-vue-next';
import { computed, ref, watch, type Component } from 'vue';

type CardStat = {
    name: string;
    oracleId: string;
    colorIdentity: string | null;
    type: string | null;
    image: string | null;
    isSideboard: boolean;
    totalGames: number;
    totalPossible: number;
    totalKept: number;
    keptWon: number;
    keptLost: number;
    totalSeen: number;
    seenWon: number;
    seenLost: number;
    postboardGames: number;
    sidedOutGames: number;
};

const props = defineProps<{
    cardStats?: {
        stats: CardStat[];
        archetypes: { id: number; name: string; colorIdentity: string | null }[];
    };
}>();

const stats = computed(() => props.cardStats?.stats ?? []);
const archetypes = computed(() => props.cardStats?.archetypes ?? []);

const selectedArchetype = ref<string>('__all__');

function filterByArchetype(value: string) {
    selectedArchetype.value = value;
    const archetypeId = value === '__all__' ? undefined : value;
    router.reload({
        only: ['cardStats'],
        data: { card_stats_archetype: archetypeId },
        preserveScroll: true,
        preserveState: true,
    });
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
            // Validate: must have all keys and at least one type enabled
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
    for (const stat of stats.value) {
        if (stat.isSideboard) types.add('Sideboard');
        const type = normalizeType(stat.type);
        if (type !== 'Other') types.add(type as FilterKey);
    }
    return types;
});

// Re-enable any hidden types when the deck data changes so stale
// filters from a previous deck can't silently hide cards.
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

function normalizeType(raw: string | null): string {
    if (!raw) return 'Other';
    const canonical: FilterKey[] = ['Creature', 'Planeswalker', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'];
    for (const type of canonical) {
        if (raw.includes(type)) return type;
    }
    return 'Other';
}

function passesFilter(stat: CardStat): boolean {
    const type = normalizeType(stat.type);

    if (stat.isSideboard) {
        // Sideboard cards are controlled by the Sideboard toggle only
        return typeFilters.value.Sideboard;
    }

    // Mainboard: filter by type — 'Other' types always show (no dedicated filter)
    if (type !== 'Other' && !typeFilters.value[type as FilterKey]) return false;

    return true;
}

// ── Sorting ──────────────────────────────────────────────────────────────────

type SortKey = 'name' | 'keptPct' | 'keptWinPct' | 'seenPct' | 'seenWinPct' | 'sbOutPct' | 'games';
const sortBy = ref<SortKey>('name');
const sortDesc = ref(false);

function pct(num: number, denom: number): number | null {
    return denom > 0 ? Math.round((num / denom) * 100) : null;
}

function toggleSort(key: SortKey) {
    if (sortBy.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortBy.value = key;
        sortDesc.value = key !== 'name';
    }
}

function sortValue(stat: CardStat, key: SortKey): number | string {
    switch (key) {
        case 'name':
            return stat.name;
        case 'keptPct':
            return pct(stat.totalKept, stat.totalPossible) ?? -1;
        case 'keptWinPct':
            return pct(stat.keptWon, stat.keptWon + stat.keptLost) ?? -1;
        case 'seenPct':
            return pct(stat.totalSeen, stat.totalPossible) ?? -1;
        case 'seenWinPct':
            return pct(stat.seenWon, stat.seenWon + stat.seenLost) ?? -1;
        case 'sbOutPct':
            return pct(stat.sidedOutGames, stat.postboardGames) ?? -1;
        case 'games':
            return stat.totalGames;
    }
}

const filteredAndSortedStats = computed(() => {
    const filtered = stats.value.filter(passesFilter);
    return [...filtered].sort((a, b) => {
        const aVal = sortValue(a, sortBy.value);
        const bVal = sortValue(b, sortBy.value);
        const cmp = aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        return sortDesc.value ? -cmp : cmp;
    });
});

function sortIcon(key: SortKey) {
    if (sortBy.value !== key) return ChevronsUpDown;
    return sortDesc.value ? ChevronDown : ChevronUp;
}

function winRateClass(pctVal: number | null): string {
    if (pctVal === null) return 'text-muted-foreground';
    if (pctVal > 55) return 'text-success';
    if (pctVal < 45) return 'text-destructive';
    return '';
}
</script>

<template>
    <Deferred data="cardStats">
        <template #fallback>
            <Card class="gap-0 overflow-hidden p-0">
                <CardContent class="flex flex-col gap-2 px-4 py-4">
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-3/4" />
                </CardContent>
            </Card>
        </template>

        <div v-if="archetypes.length || stats.length" class="mb-4 flex items-center justify-between">
            <div v-if="archetypes.length" class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">Opponent archetype:</span>
                <Select :modelValue="selectedArchetype" @update:modelValue="filterByArchetype">
                    <SelectTrigger class="h-8 w-48 text-xs">
                        <SelectValue placeholder="All archetypes" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all__">All archetypes</SelectItem>
                        <SelectItem v-for="arch in archetypes" :key="arch.id" :value="String(arch.id)">
                            {{ arch.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        :variant="activeFilterCount > 0 ? 'default' : 'outline'"
                        size="sm"
                        class="h-8 gap-1.5 text-xs"
                    >
                        <Filter class="size-3.5" />
                        <span v-if="activeFilterCount > 0">{{ activeFilterCount }} hidden</span>
                        <span v-else>Card types</span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-48">
                    <DropdownMenuLabel class="text-xs">Filter by type</DropdownMenuLabel>
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
        </div>

        <div v-if="!stats.length" class="flex flex-col items-center gap-3 py-16 text-center">
            <BarChart3 class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No card stats yet</p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Card performance stats will appear here once you've played some games. Stats may take a moment to compute after matches complete.
            </p>
        </div>

        <Card v-else class="gap-0 overflow-hidden p-0">
            <CardContent class="px-0">
                <Table>
                    <TableHeader class="bg-muted sticky top-0">
                        <TableRow>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                                <span class="inline-flex items-center gap-1">Card <component :is="sortIcon('name')" class="size-3" /></span>
                            </TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead class="w-10 text-center">SB</TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('keptPct')">
                                <span class="inline-flex items-center justify-end gap-1">Kept % <component :is="sortIcon('keptPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('keptWinPct')">
                                <span class="inline-flex items-center justify-end gap-1">Win % (kept) <component :is="sortIcon('keptWinPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('seenPct')">
                                <span class="inline-flex items-center justify-end gap-1">Seen % <component :is="sortIcon('seenPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('seenWinPct')">
                                <span class="inline-flex items-center justify-end gap-1">Win % (seen) <component :is="sortIcon('seenWinPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('sbOutPct')">
                                <span class="inline-flex items-center justify-end gap-1">SB Out % <component :is="sortIcon('sbOutPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('games')">
                                <span class="inline-flex items-center justify-end gap-1">Games <component :is="sortIcon('games')" class="size-3" /></span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="stat in filteredAndSortedStats" :key="stat.oracleId">
                            <TableCell class="font-medium">{{ stat.name ?? 'Unknown' }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ stat.type ?? '—' }}</TableCell>
                            <TableCell class="text-center">
                                <Check v-if="stat.isSideboard" class="mx-auto size-3.5 text-muted-foreground" />
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <span v-if="pct(stat.totalKept, stat.totalPossible) !== null">
                                    {{ pct(stat.totalKept, stat.totalPossible) }}%
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <span
                                    v-if="pct(stat.keptWon, stat.keptWon + stat.keptLost) !== null"
                                    class="font-medium"
                                    :class="winRateClass(pct(stat.keptWon, stat.keptWon + stat.keptLost))"
                                >
                                    {{ pct(stat.keptWon, stat.keptWon + stat.keptLost) }}%
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <span v-if="pct(stat.totalSeen, stat.totalPossible) !== null">
                                    {{ pct(stat.totalSeen, stat.totalPossible) }}%
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <span
                                    v-if="pct(stat.seenWon, stat.seenWon + stat.seenLost) !== null"
                                    class="font-medium"
                                    :class="winRateClass(pct(stat.seenWon, stat.seenWon + stat.seenLost))"
                                >
                                    {{ pct(stat.seenWon, stat.seenWon + stat.seenLost) }}%
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <span v-if="pct(stat.sidedOutGames, stat.postboardGames) !== null">
                                    {{ pct(stat.sidedOutGames, stat.postboardGames) }}%
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums text-muted-foreground">
                                {{ stat.totalGames }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </Deferred>
</template>
