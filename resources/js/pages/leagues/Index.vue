<script setup lang="ts">
import LeagueCard from '@/components/leagues/LeagueCard.vue';
import LeagueFilters from '@/components/leagues/LeagueFilters.vue';
import LeagueKpis from '@/components/leagues/LeagueKpis.vue';
import { Button } from '@/components/ui/button';
import type {
    LeagueDeckOption,
    LeagueFiltersState,
    LeagueKpis as LeagueKpisData,
    LeagueRun,
} from '@/types/leagues';
import { router } from '@inertiajs/vue3';
import { Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    leagues: {
        data: (LeagueRun | null)[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    kpis: LeagueKpisData;
    allFormats: string[];
    allDecks: LeagueDeckOption[];
    filters: LeagueFiltersState;
}>();

const displayed = computed(() => props.leagues.data.filter(Boolean) as LeagueRun[]);

function handleFilterChange(next: LeagueFiltersState) {
    router.get(
        '/leagues',
        {
            format: next.format || undefined,
            state: next.state === 'all' ? undefined : next.state,
            deck: next.deck ?? undefined,
            q: next.q || undefined,
            sort: next.sort === 'newest' ? undefined : next.sort,
        },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <header>
            <h1 class="text-2xl font-semibold">Leagues</h1>
            <p class="text-sm text-muted-foreground">
                {{ kpis.runs.total }} runs · {{ kpis.runs.completed }} completed ·
                {{ kpis.runs.live }} live · {{ kpis.runs.decks }} decks
            </p>
        </header>

        <LeagueKpis :kpis="kpis" />

        <LeagueFilters
            :filters="filters"
            :formats="allFormats"
            :decks="allDecks"
            @change="handleFilterChange"
        />

        <div
            v-if="!displayed.length"
            class="flex flex-col items-center gap-2 py-16 text-center"
        >
            <Trophy class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No league runs match.</p>
            <p class="text-sm text-muted-foreground">
                Adjust filters or wait for new runs to ingest.
            </p>
        </div>

        <div v-else class="flex flex-col gap-3">
            <LeagueCard
                v-for="(league, index) in displayed"
                :key="league.id"
                :league="league"
                :default-expanded="index === 0"
            />
        </div>

        <div v-if="leagues.last_page > 1" class="flex justify-end gap-1 px-2 py-2">
            <template v-for="link in leagues.links" :key="link.label">
                <Button
                    v-if="link.url"
                    size="sm"
                    :variant="link.active ? 'default' : 'outline'"
                    @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>
</template>
