<script setup lang="ts">
import CardStatsController from '@/actions/App/Http/Controllers/Reports/CardStatsController';
import MatchesController from '@/actions/App/Http/Controllers/Reports/MatchesController';
import ReportsSideNav from '@/components/reports/ReportsSideNav.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import type { ReportArchetypeOption, ReportArchetypeStats, ReportFormatOption, ReportsCurrentPage } from '@/types/reports';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    archetypeOptions: ReportArchetypeOption[];
    formatOptions: ReportFormatOption[];
    selectedArchetype: number | null;
    selectedFormat: string | null;
    timeframe: string;
    currentPage: ReportsCurrentPage;
    matchCount: number;
    archetypeStats: ReportArchetypeStats | null;
}>();

const controller = computed(() =>
    props.currentPage === 'matches' ? MatchesController : CardStatsController,
);

function onTimeframeChange(value: string) {
    const query: Record<string, string | number> = { timeframe: value };
    if (props.selectedArchetype !== null) {
        query.archetype = props.selectedArchetype;
    }
    if (props.selectedFormat !== null) {
        query.format = props.selectedFormat;
    }

    router.get(controller.value.url(), query, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div class="flex min-h-0 flex-1">
        <div class="w-56 shrink-0">
            <ReportsSideNav
                :current-page="currentPage"
                :archetype-options="archetypeOptions"
                :format-options="formatOptions"
                :selected-archetype="selectedArchetype"
                :selected-format="selectedFormat"
                :timeframe="timeframe"
                :archetype-stats="archetypeStats"
            />
        </div>
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto border-l border-white/5">
            <header class="flex items-center justify-end border-b border-black/60 bg-background/40 px-4 py-3">
                <TimeframeFilter :model-value="timeframe" @update:model-value="onTimeframeChange" />
            </header>
            <div class="flex-1 overflow-auto p-4">
                <slot />
            </div>
        </div>
    </div>
</template>
