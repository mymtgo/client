<script setup lang="ts">
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/vue3';
import MatchesTable from '@/components/matches/MatchesTable.vue';
import { ref, watch } from 'vue';

type Paginator<T> = { data: T[]; total: number; per_page: number; current_page: number };

type ArchetypeWithCount = App.Data.Front.ArchetypeData & { matchCount: number };

defineProps<{
    matches: Paginator<App.Data.Front.MatchData>;
    archetypes: ArchetypeWithCount[];
    unknownArchetypeCount: number;
}>();

const filterResult = ref('all');
const filterType = ref('all');
const filterArchetype = ref('all');
const sortBy = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('desc');

const applyFilters = (page = 1) => {
    const data: Record<string, string | number> = {
        page,
        filter_result: filterResult.value !== 'all' ? filterResult.value : '',
        filter_type: filterType.value !== 'all' ? filterType.value : '',
        filter_archetype: filterArchetype.value !== 'all' ? filterArchetype.value : '',
        sort: sortBy.value ?? '',
        sort_dir: sortDir.value,
    };

    router.reload({
        data,
        only: ['matches'],
        preserveScroll: true,
    });
};

watch([filterResult, filterType, filterArchetype], () => {
    applyFilters();
});

const updatePage = (page: number) => {
    applyFilters(page);
};

const updateSort = (column: string) => {
    if (sortBy.value === column) {
        if (sortDir.value === 'desc') {
            sortDir.value = 'asc';
        } else {
            sortBy.value = null;
            sortDir.value = 'desc';
        }
    } else {
        sortBy.value = column;
        sortDir.value = 'desc';
    }
    applyFilters();
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Filters -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p v-if="matches.total" class="text-muted-foreground text-xs">
                Showing {{ (matches.current_page - 1) * matches.per_page + 1 }}–{{ Math.min(matches.current_page * matches.per_page, matches.total) }} of {{ matches.total }} matches
            </p>
            <div v-else />
            <div class="flex shrink-0 items-center gap-3">
                <Select v-model="filterResult">
                    <SelectTrigger class="h-8 w-28 text-xs">
                        <SelectValue placeholder="Result" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" class="text-xs">All Results</SelectItem>
                        <SelectItem value="win" class="text-xs">Win</SelectItem>
                        <SelectItem value="loss" class="text-xs">Loss</SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filterType">
                    <SelectTrigger class="h-8 w-28 text-xs">
                        <SelectValue placeholder="Type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" class="text-xs">All Types</SelectItem>
                        <SelectItem value="league" class="text-xs">League</SelectItem>
                        <SelectItem value="casual" class="text-xs">Casual</SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filterArchetype">
                    <SelectTrigger class="h-8 w-40 text-xs">
                        <SelectValue placeholder="Archetype" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" class="text-xs">All Archetypes</SelectItem>
                        <SelectItem value="unknown" class="text-xs">Unknown ({{ unknownArchetypeCount }})</SelectItem>
                        <SelectItem v-for="arch in archetypes.filter(a => a.matchCount > 0)" :key="arch.id" :value="String(arch.id)" class="text-xs">
                            {{ arch.name }} ({{ arch.matchCount }})
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <!-- Table -->
        <Card class="gap-0 overflow-hidden p-0">
            <CardContent class="px-0">
                <p v-if="!matches.total" class="text-muted-foreground py-8 text-center text-sm">No matches recorded</p>

                <MatchesTable :matches="matches.data" :archetypes="archetypes" :sort-by="sortBy" :sort-dir="sortDir" @sort="updateSort" v-if="matches.total" />
            </CardContent>

            <div class="justify-end py-2 text-right" v-if="matches.total > matches.per_page">
                <Pagination
                    @update:page="updatePage"
                    v-slot="{ page }"
                    :items-per-page="matches.per_page"
                    :total="matches.total"
                    :default-page="1"
                >
                    <PaginationContent v-slot="{ items }">
                        <PaginationPrevious />
                        <template v-for="(item, index) in items" :key="index">
                            <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page">
                                {{ item.value }}
                            </PaginationItem>
                        </template>
                        <PaginationNext />
                    </PaginationContent>
                </Pagination>
            </div>
        </Card>
    </div>
</template>
