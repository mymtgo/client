<script setup lang="ts">
import LeagueTable from '@/components/leagues/LeagueTable.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ChevronDown, Ghost, Trophy } from 'lucide-vue-next';
import type { LeagueRun } from '@/types/leagues';
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    leagues: {
        data: (LeagueRun | null)[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    hidePhantomLeagues: boolean;
    allFormats: string[];
    filters: { format: string };
}>();

type PhantomFilter = 'include' | 'exclude' | 'only';
const phantomFilter = ref<PhantomFilter>('include');
const phantomFilterLabel: Record<PhantomFilter, string> = {
    include: 'Include phantom',
    exclude: 'Exclude phantom',
    only: 'Only phantom',
};

const activeFormat = ref(props.filters.format || 'All');

function setFormat(f: string) {
    activeFormat.value = f;
    router.get(
        '/leagues',
        {
            format: f === 'All' ? undefined : f,
        },
        { preserveState: true, preserveScroll: true },
    );
}

const runWins = (r: LeagueRun) => r.results.filter((x) => x === 'W').length;
const runLosses = (r: LeagueRun) => r.results.filter((x) => x === 'L').length;
const isComplete = (r: LeagueRun) => r.state === 'complete';
const isTrophy = (r: LeagueRun) => runWins(r) === 5 && isComplete(r) && !r.phantom;

const displayedLeagues = computed(() => {
    let runs = props.leagues.data.filter(Boolean) as LeagueRun[];
    if (!props.hidePhantomLeagues) {
        if (phantomFilter.value === 'exclude') runs = runs.filter((r) => !r.phantom);
        if (phantomFilter.value === 'only') runs = runs.filter((r) => r.phantom);
    }
    return runs;
});

const kpis = computed(() => {
    const runs = displayedLeagues.value.filter((r) => isComplete(r) && !r.phantom);
    const totalW = runs.reduce((s, r) => s + runWins(r), 0);
    const totalL = runs.reduce((s, r) => s + runLosses(r), 0);
    return {
        total: runs.length,
        trophies: runs.filter(isTrophy).length,
        fourOne: runs.filter((r) => runWins(r) === 4).length,
        winRate: totalW + totalL > 0 ? Math.round((totalW / (totalW + totalL)) * 100) : 0,
    };
});
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
            <!-- KPI bar (real leagues only) -->
            <Card>
                <CardContent class="grid grid-cols-4 divide-x divide-border p-0">
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.total }}</span>
                        <span class="text-xs text-muted-foreground">Runs</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <div class="flex items-center gap-1.5">
                            <Trophy class="size-5 text-yellow-400" />
                            <span class="text-3xl font-bold tabular-nums">{{ kpis.trophies }}</span>
                        </div>
                        <span class="text-xs text-muted-foreground">5-0</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.fourOne }}</span>
                        <span class="text-xs text-muted-foreground">4-1</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 px-6 py-4">
                        <span class="text-3xl font-bold tabular-nums">{{ kpis.winRate }}%</span>
                        <span class="text-xs text-muted-foreground">Win rate</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Format pills -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <Button
                        v-for="f in ['All', ...allFormats]"
                        :key="f"
                        size="sm"
                        :variant="activeFormat === f ? 'default' : 'outline'"
                        @click="setFormat(f)"
                    >
                        {{ f }}
                    </Button>
                </div>

                <!-- Phantom filter (hidden when global "Hide phantom leagues" setting is on) -->
                <DropdownMenu v-if="!hidePhantomLeagues">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="ml-auto gap-1.5">
                            <Ghost class="size-3.5" />
                            {{ phantomFilterLabel[phantomFilter] }}
                            <ChevronDown class="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuRadioGroup v-model="phantomFilter">
                            <DropdownMenuRadioItem value="include">Include phantom</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="exclude">Exclude phantom</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="only">Only phantom</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- Empty state -->
            <div v-if="displayedLeagues.length === 0" class="flex flex-col items-center gap-2 py-16 text-center">
                <Trophy class="size-10 text-muted-foreground/40" />
                <p class="font-medium">No league runs yet</p>
                <p class="text-sm text-muted-foreground">League runs will appear here once matches are ingested from MTGO.</p>
            </div>

            <!-- League run cards -->
            <div v-if="displayedLeagues.length" class="flex flex-col gap-4">
                <LeagueTable :league="league" :key="`league_${league.id}`" v-for="league in displayedLeagues" />
            </div>

            <!-- Pagination -->
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
