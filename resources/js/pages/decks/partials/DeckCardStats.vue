<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Deferred, router } from '@inertiajs/vue3';
import { BarChart3, ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type CardStat = {
    name: string;
    oracleId: string;
    colorIdentity: string | null;
    type: string | null;
    image: string | null;
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
        case 'name': return stat.name;
        case 'keptPct': return pct(stat.totalKept, stat.totalPossible) ?? -1;
        case 'keptWinPct': return pct(stat.keptWon, stat.keptWon + stat.keptLost) ?? -1;
        case 'seenPct': return pct(stat.totalSeen, stat.totalPossible) ?? -1;
        case 'seenWinPct': return pct(stat.seenWon, stat.seenWon + stat.seenLost) ?? -1;
        case 'sbOutPct': return pct(stat.sidedOutGames, stat.postboardGames) ?? -1;
        case 'games': return stat.totalGames;
    }
}

const sortedStats = computed(() => {
    if (!stats.value.length) return [];
    return [...stats.value].sort((a, b) => {
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

        <div v-if="archetypes.length" class="mb-4 flex items-center gap-2">
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
                        <TableRow v-for="stat in sortedStats" :key="stat.oracleId">
                            <TableCell class="font-medium">{{ stat.name ?? 'Unknown' }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ stat.type ?? '—' }}</TableCell>
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
