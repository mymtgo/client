<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import ReportsLayout from '@/layouts/ReportsLayout.vue';
import CardStatsView from '@/components/cards/CardStatsView.vue';
import CardStatsController from '@/actions/App/Http/Controllers/Reports/CardStatsController';
import { Deferred, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { DeckCardStat } from '@/types/decks';
import type { CardStatsPerspective } from '@/pages/decks/partials/cardStatsColumns';
import type { ReportArchetypeOption, ReportsSharedProps } from '@/types/reports';

defineOptions({ layout: AppLayout });

type CardStatsData = {
    stats: DeckCardStat[];
    archetypes: ReportArchetypeOption[];
    perspective: CardStatsPerspective;
};

const props = defineProps<
    ReportsSharedProps & {
        cardStats: CardStatsData | undefined;
    }
>();

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

type FilterPayload = {
    archetype?: string;
    playDraw?: string;
    board?: string;
    perspective?: string;
};

function handleFilterChange(payload: FilterPayload) {
    const query: Record<string, string | number> = {
        timeframe: props.timeframe,
    };
    if (props.selectedArchetype !== null) query.archetype = props.selectedArchetype;
    if (props.selectedFormat !== null) query.format = props.selectedFormat;
    if (payload.archetype !== undefined) query.card_stats_archetype = payload.archetype;
    if (payload.playDraw !== undefined) query.card_stats_play_draw = payload.playDraw;
    if (payload.board !== undefined) query.card_stats_board = payload.board;
    if (payload.perspective !== undefined) query.card_stats_perspective = payload.perspective;

    router.get(CardStatsController.url(), query, {
        preserveScroll: true,
        preserveState: true,
        only: ['cardStats'],
    });
}
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
        <Deferred v-else data="cardStats">
            <template #fallback>
                <div class="animate-pulse rounded border border-black/60 bg-background/60 p-8">
                    <div class="mb-3 h-4 w-1/3 rounded bg-white/10"></div>
                    <div class="mb-2 h-3 w-full rounded bg-white/5"></div>
                </div>
            </template>
            <CardStatsView
                v-if="cardStats"
                :stats="cardStats.stats"
                :archetypes="cardStats.archetypes"
                :perspective="cardStats.perspective"
                @filter-change="handleFilterChange"
            />
        </Deferred>
    </ReportsLayout>
</template>
