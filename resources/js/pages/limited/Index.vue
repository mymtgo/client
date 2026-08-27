<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import IndexController from '@/actions/App/Http/Controllers/Limited/IndexController';
import LimitedEventRow from '@/pages/limited/partials/LimitedEventRow.vue';
import LimitedFilters from '@/pages/limited/partials/LimitedFilters.vue';
import LimitedKpis from '@/pages/limited/partials/LimitedKpis.vue';
import type { LimitedFiltersState, LimitedIndexRow } from '@/types/limited';
import { Head, router } from '@inertiajs/vue3';
import { BookOpen } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

defineProps<{
    rows: LimitedIndexRow[];
    kpis: App.Data.Front.LimitedIndexKpisData;
    sets: string[];
    filters: LimitedFiltersState;
}>();

function handleFilterChange(next: LimitedFiltersState) {
    router.get(
        IndexController.url(),
        { set: next.set ?? undefined, kind: next.kind ?? undefined, timeframe: next.timeframe },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <Head title="Limited" />

        <div class="flex items-center justify-between gap-2">
            <h1 class="text-base font-semibold tracking-tight">Limited</h1>
        </div>

        <LimitedKpis :kpis="kpis" />

        <LimitedFilters :filters="filters" :sets="sets" @change="handleFilterChange" />

        <div v-if="!rows.length" class="flex flex-col items-center gap-2 py-16 text-center">
            <BookOpen class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No limited events yet.</p>
            <p class="text-sm text-muted-foreground">Join a draft league and mymtgo will record every pick.</p>
        </div>

        <div v-else class="flex flex-col gap-2">
            <LimitedEventRow
                v-for="(row, index) in rows"
                :key="row.leagueId ?? `draft-${row.draftId}`"
                :row="row"
                :default-expanded="index === 0"
            />
        </div>
    </div>
</template>
