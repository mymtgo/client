<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    matchupSpread: any[];
}>();

type SortKey = 'name' | 'matches' | 'record' | 'winrate';
const sortBy = ref<SortKey>('matches');
const sortDesc = ref(true);

function toggleSort(key: SortKey) {
    if (sortBy.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortBy.value = key;
        sortDesc.value = key !== 'name';
    }
}

function sortValue(matchup: any, key: SortKey): number | string {
    switch (key) {
        case 'name':
            return matchup.name;
        case 'matches':
            return matchup.matches;
        case 'record':
            return matchup.match_wins;
        case 'winrate':
            return matchup.match_winrate;
    }
}

function sortIcon(key: SortKey) {
    if (sortBy.value !== key) return ChevronsUpDown;
    return sortDesc.value ? ChevronDown : ChevronUp;
}

const sortedMatchups = computed(() => {
    return [...props.matchupSpread].sort((a, b) => {
        const aVal = sortValue(a, sortBy.value);
        const bVal = sortValue(b, sortBy.value);
        const cmp = aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        return sortDesc.value ? -cmp : cmp;
    });
});
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="px-0">
            <p v-if="!matchupSpread?.length" class="py-8 text-center text-sm text-muted-foreground">No matchup data yet.</p>
            <Table v-else>
                <TableHeader class="sticky top-0 z-10 backdrop-blur-sm">
                    <TableRow>
                        <TableHead class="w-8 pr-0 pl-3"></TableHead>
                        <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                            <span class="inline-flex items-center gap-1">Archetype <component :is="sortIcon('name')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('record')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Record <component :is="sortIcon('record')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('matches')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Matches <component :is="sortIcon('matches')" class="size-3"
                            /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('winrate')">
                            <span class="inline-flex items-center justify-end gap-1"
                                >Win % <component :is="sortIcon('winrate')" class="size-3"
                            /></span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="matchup in sortedMatchups" :key="matchup.archetype_id">
                        <TableCell class="w-8 pr-0 pl-3">
                            <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                        </TableCell>
                        <TableCell class="truncate">{{ matchup.name }}</TableCell>
                        <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.match_record }}</TableCell>
                        <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.matches }}</TableCell>
                        <TableCell class="text-right">
                            <span
                                class="font-medium tabular-nums"
                                :class="matchup.match_winrate > 50 ? 'text-success' : matchup.match_winrate < 50 ? 'text-destructive' : ''"
                                >{{ matchup.match_winrate }}%</span
                            >
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
