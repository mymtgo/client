<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import ReportsLayout from '@/layouts/ReportsLayout.vue';
import MatchupSpreadTable from '@/components/matchups/MatchupSpreadTable.vue';
import { Deferred } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { MatchupSpread } from '@/types/decks';
import type { ReportsSharedProps } from '@/types/reports';

defineOptions({ layout: AppLayout });

const props = defineProps<ReportsSharedProps & {
    matchupSpread: MatchupSpread[] | undefined;
}>();

type State = 'no-selection' | 'no-data' | 'ready';

const state = computed<State>(() => {
    if (props.selectedArchetype === null || props.selectedFormat === null) return 'no-selection';
    if (props.matchCount === 0) return 'no-data';
    return 'ready';
});

const archetypeName = computed(() =>
    props.selectedArchetype === null
        ? ''
        : (props.archetypeOptions.find((a) => a.id === props.selectedArchetype)?.name ?? ''),
);

const formatLabel = computed(() =>
    props.selectedFormat === null
        ? ''
        : (props.formatOptions.find((f) => f.value === props.selectedFormat)?.label ?? props.selectedFormat),
);
</script>

<template>
    <ReportsLayout
        :archetype-options="archetypeOptions"
        :format-options="formatOptions"
        :selected-archetype="selectedArchetype"
        :selected-format="selectedFormat"
        :timeframe="timeframe"
        :current-page="currentPage"
        :match-count="matchCount"
        :archetype-stats="archetypeStats"
    >
        <div
            v-if="state === 'no-selection'"
            class="rounded border border-black/60 bg-background/60 p-8 text-center text-white/70"
        >
            Choose an archetype and format to view this report.
        </div>
        <div
            v-else-if="state === 'no-data'"
            class="rounded border border-black/60 bg-background/60 p-8 text-center text-white/70"
        >
            No matches yet for {{ archetypeName }} in {{ formatLabel }}.
        </div>
        <Deferred v-else data="matchupSpread">
            <template #fallback>
                <div class="animate-pulse rounded border border-black/60 bg-background/60 p-8">
                    <div class="mb-3 h-4 w-1/3 rounded bg-white/10"></div>
                    <div class="mb-2 h-3 w-full rounded bg-white/5"></div>
                    <div class="mb-2 h-3 w-5/6 rounded bg-white/5"></div>
                </div>
            </template>
            <MatchupSpreadTable
                v-if="matchupSpread"
                :matchup-spread="matchupSpread"
                :timeframe="timeframe"
            />
        </Deferred>
    </ReportsLayout>
</template>
