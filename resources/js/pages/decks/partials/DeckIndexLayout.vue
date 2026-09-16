<script setup lang="ts">
import IndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import ToggleHideArchivedController from '@/actions/App/Http/Controllers/Decks/ToggleHideArchivedController';
import UpdateCardSizeController from '@/actions/App/Http/Controllers/Decks/UpdateCardSizeController';
import DeckArchetypeHeader from '@/pages/decks/partials/DeckArchetypeHeader.vue';
import DeckIndexSidebar from '@/pages/decks/partials/DeckIndexSidebar.vue';
import { deckIndexTabUrl } from '@/pages/decks/partials/deckIndexTabs';
import type { DeckIndexSharedProps, DeckIndexTab } from '@/types/decks';
import { router } from '@inertiajs/vue3';
import { Layers } from 'lucide-vue-next';
import { SplitterGroup, SplitterPanel, SplitterResizeHandle } from 'reka-ui';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<
        DeckIndexSharedProps & {
            tab: DeckIndexTab;
            timeframe?: string;
            selectedIds?: number[];
            /** Deck id to display format for every deck on the current page, so a picker can be scoped to the decks it will assign. */
            deckFormats?: Record<number, string>;
            /** Display format of the deck(s) being dragged, null when no drag is in flight. */
            dragFormat?: string | null;
        }
    >(),
    { timeframe: 'alltime', selectedIds: () => [], deckFormats: () => ({}), dragFormat: null },
);

const emit = defineEmits<{
    /** Fired just before a filter navigation, so the host can drop page-local state such as a selection. */
    navigate: [];
    assign: [deckIds: number[], archetypeId: number | null];
}>();

const searchInput = ref(props.filters.search);
const activeFormat = ref(props.filters.format);
const activeArchetype = ref(props.filters.archetype);
const compactCards = ref(props.filters.card_size === 'compact');

const totalDecks = computed(() => props.formatOptions.reduce((sum, option) => sum + option.count, 0));
const anyFilter = computed(() => !!props.filters.search || !!props.filters.format || !!props.filters.archetype);
const showEmptyStateEmpty = computed(() => totalDecks.value === 0 && !anyFilter.value);

/**
 * Sidebar navigation. A stats tab follows the user to the newly picked
 * archetype; anything that leaves the archetype view (Unclassified, no
 * archetype, a search) lands back on the deck grid with the filters applied.
 */
function applyFilters() {
    emit('navigate');

    const archetypeId = /^\d+$/.test(activeArchetype.value) ? Number(activeArchetype.value) : null;

    if (props.tab !== 'decks' && archetypeId !== null && !searchInput.value) {
        router.get(deckIndexTabUrl(props.tab, archetypeId, activeFormat.value, props.timeframe), {}, { preserveState: true, preserveScroll: true });
        return;
    }

    router.get(
        IndexController.url(),
        {
            // Sent even when empty: the server remembers these two, and an
            // absent key means "use what you remember", not "clear".
            format: activeFormat.value,
            archetype: activeArchetype.value,
            search: searchInput.value || undefined,
            sort: props.filters.sort !== 'lastPlayed' ? props.filters.sort : undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(searchInput, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

watch([activeFormat, activeArchetype], () => applyFilters());

// Resync local filter refs when the server's filters change without a local
// trigger (e.g. browser back/forward with preserveState). Only writes values
// that actually differ, so a normal filter round-trip (local ref changed ->
// applyFilters -> server returns matching filters) settles without looping
// back into another applyFilters call.
watch(
    () => props.filters,
    (filters) => {
        if (searchInput.value !== filters.search) searchInput.value = filters.search;
        if (activeFormat.value !== filters.format) activeFormat.value = filters.format;
        if (activeArchetype.value !== filters.archetype) activeArchetype.value = filters.archetype;
    },
);

/**
 * The archetype filter is scoped to the active format. Switching format out
 * from under a set archetype would otherwise strand the archetype query
 * param pointing at a row no longer in the sidebar, leaving an empty grid
 * with no visible sign a filter is still applied. Clearing it here lets the
 * watcher above fire once for both changes.
 */
function setFormat(value: string) {
    activeFormat.value = value;
    activeArchetype.value = '';
}

/**
 * Card size and the archived toggle are persisted app settings rather than
 * query params: the listing is reached from every deck page, and a
 * preference that resets on the next visit is worse than no preference.
 */
function toggleCompactCards(value: boolean) {
    compactCards.value = value;
    router.post(UpdateCardSizeController.url(), { size: value ? 'compact' : 'large' }, { preserveScroll: true });
}

function toggleShowArchived(value: boolean) {
    router.post(ToggleHideArchivedController.url(), { hide: !value }, { preserveScroll: true });
}
</script>

<template>
    <div v-if="showEmptyStateEmpty" class="flex flex-1 flex-col items-center justify-center gap-2 py-16 text-center">
        <Layers class="size-10 text-muted-foreground/40" />
        <p class="font-medium">No decks yet</p>
        <p class="text-sm text-muted-foreground">Decks are synced automatically from MTGO once the file watcher is running.</p>
    </div>

    <!-- Sidebar width is user-draggable; reka persists it under the autoSaveId. -->
    <SplitterGroup v-else direction="horizontal" auto-save-id="decks-index-sidebar" class="flex min-h-0 flex-1">
        <SplitterPanel :default-size="18" :min-size="12" :max-size="40" class="shrink-0">
            <DeckIndexSidebar
                v-model:search="searchInput"
                :format="activeFormat"
                v-model:archetype="activeArchetype"
                :format-options="formatOptions"
                :archetype-options="archetypeOptions"
                :unclassified-count="unclassifiedCount"
                :archetypes="archetypes"
                :compact-cards="compactCards"
                :show-archived="!filters.hide_deleted"
                :selected-ids="selectedIds"
                :deck-formats="deckFormats"
                :drag-format="dragFormat"
                @update:format="setFormat"
                @update:compact-cards="toggleCompactCards"
                @update:show-archived="toggleShowArchived"
                @assign="(ids, archetypeId) => emit('assign', ids, archetypeId)"
            />
        </SplitterPanel>

        <SplitterResizeHandle
            class="-mr-1 w-1 cursor-col-resize bg-transparent transition-colors hover:bg-primary/40 data-[state=drag]:bg-primary/60"
        />

        <SplitterPanel class="flex min-h-0 flex-col border-l border-white/5">
            <header class="flex items-center gap-2 border-b border-black/60 bg-background/40 px-4 py-2">
                <slot name="toolbar" />
            </header>

            <!-- Sits outside the scroll container so the body scrolls under it. -->
            <DeckArchetypeHeader v-if="archetypeHeader" :header="archetypeHeader" :tab="tab" :format="filters.format" :timeframe="timeframe" />

            <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto border-t border-white/5 p-3 lg:p-4">
                <slot />
            </div>

            <slot name="footer" />
        </SplitterPanel>
    </SplitterGroup>
</template>
