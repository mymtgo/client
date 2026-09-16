<script setup lang="ts">
import BulkUpdateDeckArchetypeController from '@/actions/App/Http/Controllers/Decks/BulkUpdateDeckArchetypeController';
import IndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import UpdatePerPageController from '@/actions/App/Http/Controllers/Decks/UpdatePerPageController';
import RunSyncController from '@/actions/App/Http/Controllers/Settings/RunSyncController';
import { Button } from '@/components/ui/button';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import DeckCardGrid from '@/pages/decks/partials/DeckCardGrid.vue';
import DeckIndexLayout from '@/pages/decks/partials/DeckIndexLayout.vue';
import DeckSelectionBar from '@/pages/decks/partials/DeckSelectionBar.vue';
import type { DeckIndexSharedProps } from '@/types/decks';
import { router } from '@inertiajs/vue3';
import { ArrowUpDown, RefreshCw, Rows3 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Paginator<T> = { data: T[]; total: number; per_page: number; current_page: number };

const props = defineProps<
    DeckIndexSharedProps & {
        decks: Paginator<App.Data.Front.DeckData>;
    }
>();

const sortBy = ref(props.filters.sort);
const perPage = ref(String(props.filters.per_page));
const cardSize = computed(() => props.filters.card_size);

const anyFilter = computed(() => !!props.filters.search || !!props.filters.format || !!props.filters.archetype);
const showEmptyStateFiltered = computed(() => props.decks.total === 0 && anyFilter.value);

// Selection. Replaced wholesale on every change so reactivity never depends on
// Set mutation tracking. Cleared on every filter navigation because those use
// preserveState and would otherwise carry hidden selections across pages.
const selectedIds = ref<number[]>([]);

const deckFormats = computed<Record<number, string>>(() => Object.fromEntries(props.decks.data.map((deck) => [deck.id, deck.format])));

// Same-named archetypes exist once per format, so pickers scope to the
// selection's format when every selected deck shares one.
const selectionFormat = computed(() => {
    const formats = new Set(selectedIds.value.map((id) => deckFormats.value[id]).filter(Boolean));
    return formats.size === 1 ? [...formats][0] : null;
});

// Format of the deck(s) currently being dragged, so the sidebar can refuse
// archetype rows from another format while the drag is in flight.
const dragFormat = ref<string | null>(null);

function setSelected(deckId: number, value: boolean) {
    // A selection is assigned to one archetype, and archetypes are per format.
    if (value && selectionFormat.value !== null && deckFormats.value[deckId] !== selectionFormat.value) return;
    const next = new Set(selectedIds.value);
    if (value) next.add(deckId);
    else next.delete(deckId);
    selectedIds.value = [...next];
}

function clearSelection() {
    selectedIds.value = [];
}

/** Sort and page navigation; the sidebar filters come from the server echo. */
function applySort(page = 1) {
    clearSelection();
    router.get(
        IndexController.url(),
        {
            format: props.filters.format || undefined,
            archetype: props.filters.archetype || undefined,
            search: props.filters.search || undefined,
            sort: sortBy.value !== 'lastPlayed' ? sortBy.value : undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

watch(sortBy, () => applySort());

watch(
    () => props.filters.sort,
    (sort) => {
        if (sortBy.value !== sort) sortBy.value = sort;
    },
);

function clearFilters() {
    router.get(IndexController.url(), { format: '', archetype: '' }, { preserveState: true, preserveScroll: true });
}

/**
 * Page size is a persisted app setting rather than a query param. The POST
 * does not preserve state, so the component remounts and the selection
 * resets on its own.
 */
function updatePerPage(value: string) {
    perPage.value = value;
    router.post(UpdatePerPageController.url(), { per_page: Number(value) }, { preserveScroll: true });
}

// Bulk assign. Partial reload keeps the grid, sidebar counts and selection bar
// in step from one round trip. If the walk-back redirect fires (page emptied),
// the partial headers drop and everything reloads; that is fine.
const assigning = ref(false);

function assignArchetype(deckIds: number[], archetypeId: number | null) {
    const ids = deckIds.length > 0 ? deckIds : selectedIds.value;
    if (ids.length === 0 || assigning.value) return;
    assigning.value = true;
    router.patch(
        BulkUpdateDeckArchetypeController.url(),
        { deck_ids: ids, archetype_id: archetypeId },
        {
            only: ['decks', 'archetypeOptions', 'unclassifiedCount', 'formatOptions', 'archetypeHeader'],
            preserveState: true,
            preserveScroll: true,
            onSuccess: clearSelection,
            onFinish: () => {
                assigning.value = false;
            },
        },
    );
}

const syncing = ref(false);

function syncDecks() {
    if (syncing.value) return;
    syncing.value = true;
    router.post(
        RunSyncController.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                syncing.value = false;
            },
        },
    );
}
</script>

<template>
    <DeckIndexLayout
        tab="decks"
        :format-options="formatOptions"
        :archetype-options="archetypeOptions"
        :unclassified-count="unclassifiedCount"
        :archetype-header="archetypeHeader"
        :archetypes="archetypes"
        :filters="filters"
        :selected-ids="selectedIds"
        :deck-formats="deckFormats"
        :drag-format="dragFormat"
        @navigate="clearSelection"
        @assign="assignArchetype"
    >
        <template #toolbar>
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

            <Select :model-value="perPage" @update:model-value="(value) => updatePerPage(String(value))">
                <SelectTrigger size="sm" class="w-32 gap-1.5 text-xs">
                    <Rows3 class="size-3.5 text-muted-foreground" />
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="12" class="text-xs">12 per page</SelectItem>
                    <SelectItem value="24" class="text-xs">24 per page</SelectItem>
                    <SelectItem value="48" class="text-xs">48 per page</SelectItem>
                </SelectContent>
            </Select>

            <div class="ml-auto flex items-center gap-2">
                <Pagination
                    v-if="decks.total > decks.per_page"
                    class="mx-0 w-auto"
                    v-slot="{ page }"
                    :items-per-page="decks.per_page"
                    :total="decks.total"
                    :default-page="decks.current_page"
                    @update:page="applySort"
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

                <Button variant="outline" size="sm" class="gap-1.5 text-xs" :disabled="syncing" @click="syncDecks">
                    <RefreshCw :class="['size-3.5', syncing && 'animate-spin']" />
                    {{ syncing ? 'Syncing…' : 'Sync decks' }}
                </Button>
            </div>
        </template>

        <div v-if="showEmptyStateFiltered" class="flex flex-col items-center gap-2 py-12 text-center">
            <p class="text-sm text-muted-foreground">No decks match your filters.</p>
            <Button variant="outline" size="sm" @click="clearFilters">Clear filters</Button>
        </div>

        <template v-else>
            <DeckCardGrid
                :decks="decks.data"
                :card-size="cardSize"
                :selected-ids="selectedIds"
                :selection-format="selectionFormat"
                @update:selected="setSelected"
                @dragstart="(format) => (dragFormat = format)"
                @dragend="dragFormat = null"
            />

            <Pagination
                v-if="decks.total > decks.per_page"
                class="justify-end"
                v-slot="{ page }"
                :items-per-page="decks.per_page"
                :total="decks.total"
                :default-page="decks.current_page"
                @update:page="applySort"
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

        <template #footer>
            <DeckSelectionBar
                :count="selectedIds.length"
                :archetypes="archetypes"
                :busy="assigning"
                :format="selectionFormat"
                @assign="(id) => assignArchetype([], id)"
                @dismiss="clearSelection"
            />
        </template>
    </DeckIndexLayout>
</template>
