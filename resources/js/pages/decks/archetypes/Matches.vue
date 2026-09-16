<script setup lang="ts">
import ArchetypeMatchesController from '@/actions/App/Http/Controllers/Decks/Archetypes/MatchesController';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import DeckIndexLayout from '@/pages/decks/partials/DeckIndexLayout.vue';
import DeckMatches from '@/pages/decks/partials/DeckMatches.vue';
import type { DeckIndexSharedProps } from '@/types/decks';
import { router } from '@inertiajs/vue3';

type Paginator<T> = { data: T[]; total: number; per_page: number; current_page: number };
type ArchetypeWithCount = App.Data.Front.ArchetypeData & { matchCount: number };

const props = defineProps<
    DeckIndexSharedProps & {
        archetype: number;
        timeframe: string;
        matches: Paginator<App.Data.Front.MatchData>;
        archetypes?: ArchetypeWithCount[];
        unknownArchetypeCount: number;
        pendingArchetypeCount: number;
    }
>();

function setTimeframe(value: string) {
    router.get(
        ArchetypeMatchesController.url({ archetype: props.archetype }),
        { format: props.filters.format || undefined, timeframe: value !== 'alltime' ? value : undefined },
        { preserveScroll: true },
    );
}
</script>

<template>
    <DeckIndexLayout
        tab="matches"
        :timeframe="timeframe"
        :format-options="formatOptions"
        :archetype-options="archetypeOptions"
        :unclassified-count="unclassifiedCount"
        :archetype-header="archetypeHeader"
        :archetypes="archetypes"
        :filters="filters"
    >
        <template #toolbar>
            <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />
        </template>

        <DeckMatches
            :matches="matches"
            :archetypes="archetypes ?? []"
            :unknown-archetype-count="unknownArchetypeCount"
            :pending-archetype-count="pendingArchetypeCount"
            show-deck
        />
    </DeckIndexLayout>
</template>
