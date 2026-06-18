<script setup lang="ts">
import LeagueCard from '@/components/leagues/LeagueCard.vue';
import LeagueCreateDialog from '@/components/leagues/LeagueCreateDialog.vue';
import LeagueFilters from '@/components/leagues/LeagueFilters.vue';
import LeagueKpis from '@/components/leagues/LeagueKpis.vue';
import { Button } from '@/components/ui/button';
import type {
    LeagueDeckOption,
    LeagueFiltersState,
    LeagueKpis as LeagueKpisData,
    LeagueRun,
    ManualLeagueDeckOption,
} from '@/types/leagues';
import { router } from '@inertiajs/vue3';
import { Plus, Trophy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    manualDeckOptions: ManualLeagueDeckOption[];
    filters: LeagueFiltersState;
    deckArchetypes: App.Data.Front.ArchetypeData[];
    archetypes?: App.Data.Front.ArchetypeData[];
}>();

const createDialog = ref<InstanceType<typeof LeagueCreateDialog> | null>(null);

const displayed = computed(() => props.leagues.data.filter(Boolean) as LeagueRun[]);

function handleFilterChange(next: LeagueFiltersState) {
    router.get(
        '/leagues',
        {
            format: next.format || undefined,
            state: next.state === 'all' ? undefined : next.state,
            deck: next.deck ?? undefined,
            archetype: next.archetype ?? undefined,
            q: next.q || undefined,
            sort: next.sort === 'newest' ? undefined : next.sort,
        },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-base font-semibold tracking-tight">Leagues</h1>
            <Button size="sm" @click="createDialog?.open()">
                <Plus class="size-4" />
                Create league
            </Button>
        </div>

        <LeagueKpis :kpis="kpis" />

        <LeagueFilters
            :filters="filters"
            :formats="allFormats"
            :decks="allDecks"
            :archetypes="deckArchetypes"
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
                :archetypes="archetypes ?? []"
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

        <LeagueCreateDialog ref="createDialog" :decks="manualDeckOptions" />
    </div>
</template>
