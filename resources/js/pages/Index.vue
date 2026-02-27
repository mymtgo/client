<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AppLayout from '@/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import DashboardLeague from '@/pages/partials/DashboardLeague.vue';
import DashboardFormatChart from '@/pages/partials/DashboardFormatChart.vue';
import DashboardDecks from '@/pages/partials/DashboardDecks.vue';
import DashboardRecentMatches from '@/pages/partials/DashboardRecentMatches.vue';
import { LayoutDashboard } from 'lucide-vue-next';
import { computed } from 'vue';

type ActiveLeague = {
    name: string;
    format: string;
    phantom: boolean;
    isActive: boolean;
    isTrophy: boolean;
    deckName: string | null;
    results: ('W' | 'L' | null)[];
    wins: number;
    losses: number;
    matchesRemaining: number;
};

type FormatChart = {
    months: string[];
    formats: string[];
    data: ({ x: number } & Record<string, number | null>)[];
};

type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: Record<string, unknown>;
};

const props = defineProps<{
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    recentMatches: Paginator<App.Data.Front.MatchData>;
    deckStats: App.Data.Front.DeckData[];
    timeframe: string;
    activeLeague: ActiveLeague | null;
    formatChart: FormatChart;
}>();

const timeframes = [
    { value: 'week', label: '7 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'monthly', label: '30 days' },
    { value: 'year', label: 'This year' },
    { value: 'alltime', label: 'All time' },
];

const hasData = computed(() => props.matchesWon + props.matchesLost > 0);

function setTimeframe(value: string) {
    router.get('/', { timeframe: value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="flex flex-col gap-4 p-3 lg:p-4">
            <!-- Timeframe selector -->
            <div class="flex items-center gap-1 self-start rounded-md border p-1">
                <Button
                    v-for="tf in timeframes"
                    :key="tf.value"
                    size="sm"
                    :variant="timeframe === tf.value ? 'default' : 'ghost'"
                    class="h-7 px-3 text-xs"
                    @click="setTimeframe(tf.value)"
                >
                    {{ tf.label }}
                </Button>
            </div>

            <!-- Empty state -->
            <div v-if="!hasData" class="flex flex-col items-center gap-2 py-16 text-center">
                <LayoutDashboard class="size-10 text-muted-foreground/40" />
                <p class="font-medium">No match data yet</p>
                <p class="text-sm text-muted-foreground">Start the file watcher in Settings to begin tracking your MTGO matches.</p>
            </div>

            <template v-else>
                <!-- KPI row -->
                <Card>
                    <CardContent class="grid grid-cols-4 divide-x divide-border p-0">
                        <div class="flex flex-col items-center gap-1 px-4 py-3">
                            <span class="text-3xl font-bold tabular-nums" :class="matchWinrate < 50 ? 'text-destructive' : ''">{{ matchWinrate }}%</span>
                            <span class="text-xs text-muted-foreground">Match Win Rate</span>
                        </div>
                        <div class="flex flex-col items-center gap-1 px-4 py-3">
                            <span class="text-3xl font-bold tabular-nums" :class="gameWinrate < 50 ? 'text-destructive' : ''">{{ gameWinrate }}%</span>
                            <span class="text-xs text-muted-foreground">Game Win Rate</span>
                        </div>
                        <div class="flex flex-col items-center gap-1 px-4 py-3">
                            <span class="text-3xl font-bold tabular-nums">{{ matchesWon + matchesLost }}</span>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ matchesWon }}W – {{ matchesLost }}L</span>
                        </div>
                        <div class="flex flex-col items-center gap-1 px-4 py-3">
                            <span class="text-3xl font-bold tabular-nums">{{ gamesWon + gamesLost }}</span>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ gamesWon }}W – {{ gamesLost }}L</span>
                        </div>
                    </CardContent>
                </Card>

                <DashboardLeague :league="activeLeague" />
                <DashboardFormatChart :format-chart="formatChart" />
                <DashboardDecks :deck-stats="deckStats" />
                <DashboardRecentMatches :matches="recentMatches" />
            </template>
        </div>
    </AppLayout>
</template>
