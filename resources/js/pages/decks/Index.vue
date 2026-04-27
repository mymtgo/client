<script setup lang="ts">
import ToggleGroupingController from '@/actions/App/Http/Controllers/Decks/ToggleGroupingController';
import IndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import RunSyncController from '@/actions/App/Http/Controllers/Settings/RunSyncController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import ArchetypeGroup from '@/pages/decks/partials/ArchetypeGroup.vue';
import DeckCard from '@/pages/decks/partials/DeckCard.vue';
import { router } from '@inertiajs/vue3';
import { ArrowUpDown, Layers, RefreshCw, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Paginator<T> = { data: T[]; total: number; per_page: number; current_page: number };

type FlatProps = {
    mode: 'flat';
    decks: Paginator<App.Data.Front.DeckData>;
    formats: Record<string, string>;
    filters: { format: string; search: string; sort: string };
};

type GroupedProps = {
    mode: 'grouped';
    groups: App.Data.Front.DeckGroupData[];
    formats: Record<string, string>;
    filters: { format: string; search: string; sort: string };
};

const props = defineProps<FlatProps | GroupedProps>();

const searchInput = ref(props.filters.search);
const activeFormat = ref(props.filters.format || 'all');
const sortBy = ref(props.filters.sort);

const hasAnyDecks = computed(() => {
    if (props.mode === 'flat') return (props.decks?.total ?? 0) > 0;
    return (props.groups ?? []).some((g) => g.decks.length > 0);
});

const showEmptyStateEmpty = computed(() => !hasAnyDecks.value && !props.filters.search && !props.filters.format);
const showEmptyStateFiltered = computed(() => !hasAnyDecks.value && (!!props.filters.search || !!props.filters.format));

function applyFilters(page = 1) {
    router.get(
        IndexController.url(),
        {
            format: activeFormat.value !== 'all' ? activeFormat.value : undefined,
            search: searchInput.value || undefined,
            sort: sortBy.value !== 'lastPlayed' ? sortBy.value : undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function toggleGrouping(value: boolean) {
    router.post(
        ToggleGroupingController.url(),
        { grouped: value },
        { preserveScroll: true },
    );
}

const syncing = ref(false);

function syncDecks() {
    if (syncing.value) return;
    syncing.value = true;
    router.post(RunSyncController.url(), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            syncing.value = false;
        },
    });
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(searchInput, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

watch([activeFormat, sortBy], () => {
    applyFilters();
});

function updatePage(page: number) {
    applyFilters(page);
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <div v-if="showEmptyStateEmpty" class="flex flex-col items-center gap-2 py-16 text-center">
            <Layers class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No decks yet</p>
            <p class="text-sm text-muted-foreground">Decks are synced automatically from MTGO once the file watcher is running.</p>
        </div>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        v-model="searchInput"
                        placeholder="Search decks..."
                        class="h-8 w-48 py-0 pl-7 text-xs"
                    />
                </div>

                <Select v-model="sortBy">
                    <SelectTrigger size="sm" class="w-36 gap-1.5 text-xs">
                        <ArrowUpDown class="size-3.5 text-muted-foreground" />
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="lastPlayed" class="text-xs">Last Played</SelectItem>
                        <SelectItem value="winRate" class="text-xs">Win Rate</SelectItem>
                        <SelectItem value="matchCount" class="text-xs">Match Count</SelectItem>
                        <SelectItem value="name" class="text-xs">Name</SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="activeFormat">
                    <SelectTrigger size="sm" class="w-36 text-xs">
                        <SelectValue placeholder="All Formats" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all" class="text-xs">All Formats</SelectItem>
                        <SelectItem v-for="(label, raw) in formats" :key="raw" :value="raw" class="text-xs">
                            {{ label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <div class="ml-auto flex items-center gap-2">
                    <Label for="group-by-archetype" class="cursor-pointer text-xs">Group by archetype</Label>
                    <Switch
                        id="group-by-archetype"
                        :modelValue="mode === 'grouped'"
                        @update:modelValue="toggleGrouping"
                    />
                </div>

                <Button
                    variant="outline"
                    size="sm"
                    class="gap-1.5 text-xs"
                    :disabled="syncing"
                    @click="syncDecks"
                >
                    <RefreshCw :class="['size-3.5', syncing && 'animate-spin']" />
                    {{ syncing ? 'Syncing…' : 'Sync decks' }}
                </Button>

                <Pagination
                    v-if="mode === 'flat' && decks && decks.total > decks.per_page"
                    class="mx-0 ml-auto w-auto"
                    @update:page="updatePage"
                    v-slot="{ page }"
                    :items-per-page="decks.per_page"
                    :total="decks.total"
                    :default-page="decks.current_page"
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

            <div v-if="showEmptyStateFiltered" class="flex flex-col items-center gap-2 py-12 text-center">
                <p class="text-sm text-muted-foreground">No decks match your filters.</p>
            </div>

            <template v-else-if="mode === 'grouped'">
                <ArchetypeGroup
                    v-for="group in groups"
                    :key="group.archetype?.id ?? 'unassigned'"
                    :archetype="group.archetype"
                    :stats="group.stats"
                    :decks="group.decks"
                />
            </template>

            <template v-else-if="mode === 'flat' && decks">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <DeckCard v-for="deck in decks.data" :key="deck.id" :deck="deck" />
                </div>

                <Pagination
                    v-if="decks.total > decks.per_page"
                    class="justify-end"
                    @update:page="updatePage"
                    v-slot="{ page }"
                    :items-per-page="decks.per_page"
                    :total="decks.total"
                    :default-page="decks.current_page"
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
            </template>
        </template>
    </div>
</template>
