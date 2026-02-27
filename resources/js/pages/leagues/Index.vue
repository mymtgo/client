<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import LeagueTable from '@/components/leagues/LeagueTable.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import { ChevronDown, Ghost, Trophy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

dayjs.extend(relativeTime);

type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string | null;
    opponentArchetype: string | null;
    games: string;
    startedAt: string;
};

type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string } | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    matches: LeagueMatch[];
};

const props = defineProps<{
    leagues: LeagueRun[];
    hidePhantomLeagues: boolean;
}>();

type PhantomFilter = 'include' | 'exclude' | 'only';
const phantomFilter = ref<PhantomFilter>('include');
const phantomFilterLabel: Record<PhantomFilter, string> = {
    include: 'Include phantom',
    exclude: 'Exclude phantom',
    only: 'Only phantom',
};

const allFormats = computed(() => [...new Set(props.leagues.map((r) => r.format))].sort());
const activeFormat = ref('All');

const runWins = (r: LeagueRun) => r.results.filter((x) => x === 'W').length;
const runLosses = (r: LeagueRun) => r.results.filter((x) => x === 'L').length;
const isComplete = (r: LeagueRun) => r.results.every((x) => x !== null);
const isTrophy = (r: LeagueRun) => runWins(r) === 5 && isComplete(r) && !r.phantom;

const filteredRuns = computed(() =>
    props.leagues
        .filter((r) => activeFormat.value === 'All' || r.format === activeFormat.value)
        .filter((r) => {
            if (phantomFilter.value === 'exclude') return !r.phantom;
            if (phantomFilter.value === 'only') return r.phantom;
            return true;
        })
        .slice()
        .sort((a, b) => dayjs(b.startedAt).diff(dayjs(a.startedAt))),
);

const kpis = computed(() => {
    const runs = filteredRuns.value.filter((r) => isComplete(r) && !r.phantom);
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
    <AppLayout title="Leagues">
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
                        @click="activeFormat = f"
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
            <div v-if="filteredRuns.length === 0" class="flex flex-col items-center gap-2 py-16 text-center">
                <Trophy class="size-10 text-muted-foreground/40" />
                <p class="font-medium">No league runs yet</p>
                <p class="text-sm text-muted-foreground">League runs will appear here once matches are ingested from MTGO.</p>
            </div>

            <!-- League run cards -->
            <div v-if="filteredRuns.length" class="flex flex-col gap-4">
                <LeagueTable :league="league" :key="`league_${league.id}`" v-for="league in filteredRuns" />
            </div>
        </div>
    </AppLayout>
</template>
