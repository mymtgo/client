<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import LimitedEventLayout from '@/Layouts/LimitedEventLayout.vue';
import { ASCENDING_BY_DEFAULT, COLUMNS, compareRows, type SortKey } from '@/pages/limited/partials/limitedCardColumns';
import {
    cardFor,
    NO_VALUE,
    ordinalLabel,
    POOL_STATUS_LABELS,
    POOL_STATUS_ORDER,
    poolStatusTint,
    type LimitedCardTable,
    type PoolStatus,
} from '@/types/limited';
import { Head } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Check, Layers } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: [AppLayout, LimitedEventLayout] });

const props = defineProps<{
    event: App.Data.Front.LimitedEventData;
    currentPage: string;
    table?: LimitedCardTable;
}>();

const ALL = 'all';

const filter = ref<typeof ALL | PoolStatus>(ALL);
const sortKey = ref<SortKey>('pick');
const sortDir = ref<'asc' | 'desc'>('asc');

/**
 * Nothing registered yet means every card reads as "Pool", so that segment
 * only earns its place once a row actually carries the status.
 */
const filterOptions = computed(() => {
    const present = new Set((props.table?.rows ?? []).map((row) => row.status));
    const statuses = POOL_STATUS_ORDER.filter((status) => status !== 'pool' || present.has('pool'));

    return [{ value: ALL, label: 'All' }, ...statuses.map((status) => ({ value: status, label: POOL_STATUS_LABELS[status] }))];
});

function toggleSort(key: SortKey): void {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }

    sortKey.value = key;
    sortDir.value = ASCENDING_BY_DEFAULT.includes(key) ? 'asc' : 'desc';
}

const rows = computed(() => {
    if (!props.table) {
        return [];
    }

    const cards = props.table.cards;
    const names = (id: number) => cardFor(cards, id).name;
    const list = props.table.rows.filter((row) => filter.value === ALL || row.status === filter.value);
    const sorted = [...list].sort((a, b) => compareRows(a, b, sortKey.value, names));

    return sortDir.value === 'asc' ? sorted : sorted.reverse();
});

function alignClass(align: 'left' | 'right' | 'center'): string {
    if (align === 'right') {
        return 'text-right';
    }
    return align === 'center' ? 'text-center' : '';
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <Head :title="`${event.title} · Cards`" />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-base font-semibold tracking-tight">Cards</h1>
                <p v-if="table" class="text-xs text-muted-foreground">
                    {{ table.summary.distinct }} distinct card{{ table.summary.distinct === 1 ? '' : 's' }} drafted · game stats from
                    {{ table.summary.games }} game{{ table.summary.games === 1 ? '' : 's' }} with this deck
                    <template v-if="table.summary.otherDrafts">
                        · prior columns from {{ table.summary.otherDrafts }} other {{ event.setCode }} draft{{
                            table.summary.otherDrafts === 1 ? '' : 's'
                        }}
                    </template>
                </p>
                <Skeleton v-else class="h-4 w-96" />
            </div>
            <SegmentedControl
                v-if="table"
                :model-value="filter"
                :options="filterOptions"
                @update:model-value="(value) => (filter = value as typeof ALL | PoolStatus)"
            />
        </div>

        <Skeleton v-if="!table" class="h-96 w-full" />
        <div v-else-if="!table.rows.length" class="flex flex-col items-center gap-2 py-16 text-center">
            <Layers class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No picks recorded.</p>
            <p class="text-sm text-muted-foreground">Cards appear here once a draft has been tracked for this event.</p>
        </div>
        <div v-else class="overflow-x-auto rounded-lg border border-black/60">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead
                            v-for="col in COLUMNS"
                            :key="col.key"
                            class="cursor-pointer whitespace-nowrap select-none"
                            :class="alignClass(col.align)"
                            @click="toggleSort(col.key)"
                        >
                            <span class="inline-flex items-center gap-1">
                                {{ col.label }}
                                <component :is="sortDir === 'asc' ? ArrowUp : ArrowDown" v-if="sortKey === col.key" class="size-3" />
                            </span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in rows" :key="row.catalogId">
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <img
                                    v-if="cardFor(table.cards, row.catalogId).artCrop"
                                    :src="cardFor(table.cards, row.catalogId).artCrop ?? undefined"
                                    class="size-7 shrink-0 rounded object-cover"
                                    :alt="cardFor(table.cards, row.catalogId).name"
                                />
                                <span v-else class="size-7 shrink-0 rounded bg-muted" />
                                <div class="flex flex-col leading-tight">
                                    <span>{{ cardFor(table.cards, row.catalogId).name }}</span>
                                    <span class="text-[11px] text-muted-foreground">
                                        {{
                                            [cardFor(table.cards, row.catalogId).type, cardFor(table.cards, row.catalogId).rarity]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </span>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell class="whitespace-nowrap tabular-nums">{{ row.labels.join(' · ') }}</TableCell>
                        <TableCell class="text-center">
                            <Badge variant="outline" :class="poolStatusTint(row.status)">{{ POOL_STATUS_LABELS[row.status] }}</Badge>
                        </TableCell>
                        <TableCell class="text-right tabular-nums">{{ row.gamesCast }}</TableCell>
                        <TableCell
                            class="text-right tabular-nums"
                            :class="row.winPctCast === null ? 'text-muted-foreground' : row.winPctCast >= 50 ? 'text-emerald-400' : 'text-rose-400'"
                        >
                            {{ row.winPctCast !== null ? `${row.winPctCast}%` : NO_VALUE }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">{{ row.seenCount }}</TableCell>
                        <TableCell class="text-center">
                            <Check v-if="row.wheeled" class="mx-auto size-4 text-muted-foreground" />
                            <span v-else class="text-muted-foreground">{{ NO_VALUE }}</span>
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap text-muted-foreground tabular-nums">
                            <template v-if="row.priorDrafts === 0">{{ NO_VALUE }}</template>
                            <template v-else-if="row.priorTaken > 0">
                                taken {{ row.priorTaken }}×<template v-if="row.priorAvgOrdinal !== null">
                                    · avg {{ ordinalLabel(Math.round(row.priorAvgOrdinal)) }}</template
                                >
                            </template>
                            <template v-else-if="row.priorWheeled > 0">wheeled in {{ row.priorWheeled }} of {{ row.priorDrafts }}</template>
                            <template v-else>passed</template>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
