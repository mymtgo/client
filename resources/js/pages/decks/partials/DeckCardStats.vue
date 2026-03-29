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
    Image,
    Gem,
    HandFist,
    MountainSnow,
    Origami,
    PanelRightOpen,
    ScrollText,
    Zap,
} from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { CircleHelp, Search } from 'lucide-vue-next';
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
    totalCast: number;
    castWon: number;
    castLost: number;
    postboardGames: number;
    sidedOutGames: number;
    sidedInGames: number;
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
const selectedPlayDraw = ref<string>('__all__');
const selectedBoard = ref<string>('__all__');
const searchQuery = ref('');

function reloadStats() {
    const archetypeId = selectedArchetype.value === '__all__' ? undefined : selectedArchetype.value;
    const playDraw = selectedPlayDraw.value === '__all__' ? undefined : selectedPlayDraw.value;
    const board = selectedBoard.value === '__all__' ? undefined : selectedBoard.value;
    router.reload({
        only: ['cardStats'],
        data: { card_stats_archetype: archetypeId, card_stats_play_draw: playDraw, card_stats_board: board },
        preserveScroll: true,
        preserveState: true,
    });
}

function filterByArchetype(value: string) {
    selectedArchetype.value = value;
    reloadStats();
}

function filterByPlayDraw(value: string) {
    selectedPlayDraw.value = value;
    reloadStats();
}

function filterByBoard(value: string) {
    selectedBoard.value = value;
    reloadStats();
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

type SortKey = 'name' | 'keptPct' | 'keptWinPct' | 'seenPct' | 'seenWinPct' | 'castPct' | 'castWinPct' | 'sbOutPct' | 'sbInPct' | 'games';
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

function pctWithTiebreak(num: number, denom: number): number {
    if (denom <= 0) return -1;
    return Math.round((num / denom) * 100) + denom / 10000;
}

function sortValue(stat: CardStat, key: SortKey): number | string {
    switch (key) {
        case 'name':
            return stat.name;
        case 'keptPct':
            return pctWithTiebreak(stat.totalKept, stat.totalPossible);
        case 'keptWinPct':
            return pctWithTiebreak(stat.keptWon, stat.keptWon + stat.keptLost);
        case 'seenPct':
            return pctWithTiebreak(stat.totalSeen, stat.totalPossible);
        case 'seenWinPct':
            return pctWithTiebreak(stat.seenWon, stat.seenWon + stat.seenLost);
        case 'castPct':
            return pctWithTiebreak(stat.totalCast, stat.totalPossible);
        case 'castWinPct':
            return pctWithTiebreak(stat.castWon, stat.castWon + stat.castLost);
        case 'sbOutPct':
            return pctWithTiebreak(stat.sidedOutGames, stat.postboardGames);
        case 'sbInPct':
            return pctWithTiebreak(stat.sidedInGames, stat.postboardGames);
        case 'games':
            return stat.totalGames;
    }
}

const filteredAndSortedStats = computed(() => {
    const q = searchQuery.value.toLowerCase();
    const filtered = stats.value.filter((s) => passesFilter(s) && (!q || s.name.toLowerCase().includes(q)));
    return [...filtered].sort((a, b) => {
        const aVal = sortValue(a, sortBy.value);
        const bVal = sortValue(b, sortBy.value);
        const cmp = aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        return sortDesc.value ? -cmp : cmp;
    });
});

// ── Card image hover ────────────────────────────────────────────────────────
const hoveredImage = ref<string | null>(null);
const mouseX = ref(0);
const mouseY = ref(0);

function onRowEnter(stat: CardStat) {
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

        <div v-if="archetypes.length || stats.length" class="mb-4 flex flex-col gap-4">
            <div class="flex items-center gap-4">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="searchQuery" placeholder="Search cards..." class="h-8 py-0 pl-7 text-xs" />
                </div>

                <div class="flex items-center gap-1 rounded-md border p-1">
                    <Button
                        v-for="opt in [
                            { value: '__all__', label: 'Overall' },
                            { value: 'preboard', label: 'Game 1' },
                            { value: 'postboard', label: 'Postboard' },
                        ]"
                        :key="opt.value"
                        size="sm"
                        :variant="selectedBoard === opt.value ? 'default' : 'ghost'"
                        class="h-7 px-3 text-xs"
                        @click="filterByBoard(opt.value)"
                    >
                        {{ opt.label }}
                    </Button>
                </div>

                <div class="flex items-center gap-2">
                    <Select :modelValue="selectedPlayDraw" @update:modelValue="filterByPlayDraw">
                    <SelectTrigger class="h-8 w-36 text-xs">
                        <SelectValue placeholder="Play / Draw" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all__">Play & Draw</SelectItem>
                        <SelectItem value="play">On the Play</SelectItem>
                        <SelectItem value="draw">On the Draw</SelectItem>
                    </SelectContent>
                </Select>

                <Select v-if="archetypes.length" :modelValue="selectedArchetype" @update:modelValue="filterByArchetype">
                    <SelectTrigger class="h-8 w-48 text-xs">
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
                            <Button
                                :variant="activeFilterCount > 0 ? 'default' : 'outline'"
                                class="h-[34px] gap-1.5 rounded-md border px-3 text-xs"
                            >
                                <Filter class="size-3.5" />
                                <span v-if="activeFilterCount > 0">{{ activeFilterCount }} hidden</span>
                                <span v-else>Card types</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-48">
                            <div class="flex items-center justify-between px-2 py-1.5">
                                <span class="text-xs font-semibold">Filter by type</span>
                                <button
                                    class="text-xs text-muted-foreground hover:text-foreground"
                                    @click="toggleAll"
                                >
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

                    <Sheet>
                        <SheetTrigger as-child>
                            <Button variant="ghost" size="sm" class="h-[34px] gap-1.5 px-2.5 text-xs text-muted-foreground">
                                <CircleHelp class="size-3.5" />
                                <span class="hidden lg:inline">Help</span>
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="right" class="overflow-y-auto sm:max-w-md">
                            <SheetHeader>
                                <SheetTitle>Understanding Card Stats</SheetTitle>
                                <SheetDescription>How to read the metrics on this page and what they mean for your deck.</SheetDescription>
                            </SheetHeader>
                            <div class="flex flex-col gap-6 px-4 pb-6">
                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Kept %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        The percentage of games where this card appeared in your opening hand and was kept (not mulliganed away). The number in brackets is the raw count of games kept.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">23% (12)</span> means the card was kept in 12 out of ~52 possible games.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Kept Win %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        Your win rate in games where this card was kept in your opening hand. The number in brackets is the sample size (total games where the card was kept).
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">38% (8)</span> means you won 38% of the 8 games where this card was kept. A high Kept Win % suggests the card is strong in openers.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Cast %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        The percentage of games where this card was actually cast (put on the stack). The number in brackets is the raw count of games where the card was cast.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">27% (14)</span> means you cast the card in 14 games. A low Cast % on a mainboard card may indicate it's hard to cast or frequently sided out.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Cast Win %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        Your win rate in games where this card was cast. The number in brackets is the sample size. This shows correlation, not causation &mdash; a low Cast Win % doesn't necessarily mean the card is bad; you might only cast it when behind.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">75% (4)</span> means you won 3 out of 4 games where the card was cast. Look for cards with decent sample sizes (5+) to draw meaningful conclusions.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Seen %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        The percentage of games where this card left your library &mdash; whether drawn naturally, tutored, milled, or exiled. Any card that appeared in your hand, on the battlefield, in the graveyard, in exile, or on the stack counts as "seen." The number in brackets is the raw count of games seen.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">54% (28)</span> means the card was seen in 28 games. Seen % is usually higher than Cast % since it includes cards drawn but never cast.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Seen Win %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        Your win rate in games where this card was seen (drawn or otherwise left the library). The number in brackets is the sample size.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">40% (10)</span> means you won 4 out of 10 games where this card was seen. Compare Seen Win % with Cast Win % &mdash; a big gap may indicate the card is only good when you can actually cast it.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">SB Out %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        The percentage of postboard games (games 2 and 3) where this card was sided out of your deck. The number in brackets is the count of games it was removed. Only applies to postboard games.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">50% (10)</span> means the card was sided out in 10 postboard games. A high SB Out % on a mainboard card may suggest it's a frequent sideboard cut in your meta.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">SB In %</h3>
                                    <p class="text-sm text-muted-foreground">
                                        The percentage of postboard games (games 2 and 3) where this card was sided into your deck from the sideboard. The number in brackets is the count of games it was brought in. Only applies to postboard games.
                                    </p>
                                    <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                        <span class="font-medium">Example:</span> <span class="font-mono">75% (6)</span> means the card was sided in for 6 postboard games. A high SB In % on a sideboard card shows it's frequently relevant in your meta.
                                    </div>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Games</h3>
                                    <p class="text-sm text-muted-foreground">
                                        The total number of games played with this card in the deck. This is the denominator for most percentage calculations. Cards with low game counts will have less reliable statistics.
                                    </p>
                                </section>

                                <section>
                                    <h3 class="mb-1 text-sm font-semibold">Reading Win Rate Colors</h3>
                                    <p class="text-sm text-muted-foreground">
                                        Win rate values are color-coded: <span class="font-medium text-success">green</span> for win rates above 55%, <span class="font-medium text-destructive">red</span> for win rates below 45%, and neutral for values in between. These thresholds help you quickly spot over- and under-performers.
                                    </p>
                                </section>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </div>

        <div v-if="!stats.length" class="flex flex-col items-center gap-3 py-16 text-center">
            <BarChart3 class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No card stats yet</p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Card performance stats will appear here once you've played some games. Stats may take a moment to compute after matches complete.
            </p>
        </div>

        <div v-else-if="!filteredAndSortedStats.length" class="flex flex-col items-center gap-3 py-16 text-center">
            <Filter class="size-10 text-muted-foreground/40" />
            <p class="font-medium">All card types are hidden</p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Enable some card types in the filter to view stats.
            </p>
        </div>

        <Card v-else class="gap-0 p-0">
            <CardContent class="px-0 [&_[data-slot=table-container]]:overflow-visible">
                <Table>
                    <TableHeader class="bg-muted sticky top-0 z-10">
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
                                <span class="inline-flex items-center justify-end gap-1">Kept Win % <component :is="sortIcon('keptWinPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('castPct')">
                                <span class="inline-flex items-center justify-end gap-1">Cast % <component :is="sortIcon('castPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('castWinPct')">
                                <span class="inline-flex items-center justify-end gap-1">Cast Win % <component :is="sortIcon('castWinPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('seenPct')">
                                <span class="inline-flex items-center justify-end gap-1">Seen % <component :is="sortIcon('seenPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('seenWinPct')">
                                <span class="inline-flex items-center justify-end gap-1">Seen Win % <component :is="sortIcon('seenWinPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('sbOutPct')">
                                <span class="inline-flex items-center justify-end gap-1">SB Out % <component :is="sortIcon('sbOutPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('sbInPct')">
                                <span class="inline-flex items-center justify-end gap-1">SB In % <component :is="sortIcon('sbInPct')" class="size-3" /></span>
                            </TableHead>
                            <TableHead class="cursor-pointer select-none text-right" @click="toggleSort('games')">
                                <span class="inline-flex items-center justify-end gap-1">Games <component :is="sortIcon('games')" class="size-3" /></span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="stat in filteredAndSortedStats" :key="stat.oracleId">
                            <TableCell class="font-medium">
                                <span class="flex items-center gap-1.5">
                                    <Image
                                        v-if="stat.image"
                                        class="size-3.5 shrink-0 cursor-pointer text-zinc-600 hover:text-zinc-400"
                                        @mouseenter="onRowEnter(stat)"
                                        @mousemove="onRowMove"
                                        @mouseleave="onRowLeave"
                                    />
                                    {{ stat.name ?? 'Unknown' }}
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{ stat.type ?? '—' }}</TableCell>
                            <TableCell class="text-center">
                                <Check v-if="stat.isSideboard" class="mx-auto size-3.5 text-muted-foreground" />
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.totalKept, stat.totalPossible) !== null">
                                    {{ pct(stat.totalKept, stat.totalPossible) }}%
                                    <span class="text-muted-foreground text-[10px]">({{ stat.totalKept }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.keptWon, stat.keptWon + stat.keptLost) !== null">
                                    <span
                                        class="font-medium"
                                        :class="winRateClass(pct(stat.keptWon, stat.keptWon + stat.keptLost))"
                                    >
                                        {{ pct(stat.keptWon, stat.keptWon + stat.keptLost) }}%
                                    </span>
                                    <span class="text-muted-foreground text-[10px]">({{ stat.keptWon + stat.keptLost }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.totalCast, stat.totalPossible) !== null">
                                    {{ pct(stat.totalCast, stat.totalPossible) }}%
                                    <span class="text-muted-foreground text-[10px]">({{ stat.totalCast }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.castWon, stat.castWon + stat.castLost) !== null">
                                    <span
                                        class="font-medium"
                                        :class="winRateClass(pct(stat.castWon, stat.castWon + stat.castLost))"
                                    >
                                        {{ pct(stat.castWon, stat.castWon + stat.castLost) }}%
                                    </span>
                                    <span class="text-muted-foreground text-[10px]">({{ stat.castWon + stat.castLost }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.totalSeen, stat.totalPossible) !== null">
                                    {{ pct(stat.totalSeen, stat.totalPossible) }}%
                                    <span class="text-muted-foreground text-[10px]">({{ stat.totalSeen }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.seenWon, stat.seenWon + stat.seenLost) !== null">
                                    <span
                                        class="font-medium"
                                        :class="winRateClass(pct(stat.seenWon, stat.seenWon + stat.seenLost))"
                                    >
                                        {{ pct(stat.seenWon, stat.seenWon + stat.seenLost) }}%
                                    </span>
                                    <span class="text-muted-foreground text-[10px]">({{ stat.seenWon + stat.seenLost }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.sidedOutGames, stat.postboardGames) !== null">
                                    {{ pct(stat.sidedOutGames, stat.postboardGames) }}%
                                    <span class="text-muted-foreground text-[10px]">({{ stat.sidedOutGames }})</span>
                                </template>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="pct(stat.sidedInGames, stat.postboardGames) !== null">
                                    {{ pct(stat.sidedInGames, stat.postboardGames) }}%
                                    <span class="text-muted-foreground text-[10px]">({{ stat.sidedInGames }})</span>
                                </template>
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

    <Teleport to="body">
        <img
            v-if="hoveredImage"
            :src="hoveredImage"
            class="pointer-events-none fixed z-50 w-56 rounded-lg shadow-xl"
            :style="{ top: `${mouseY - 160}px`, left: `${mouseX + 16}px` }"
        />
    </Teleport>
</template>
