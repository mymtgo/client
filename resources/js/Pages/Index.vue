<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { NativeSelect } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { router, usePoll } from '@inertiajs/vue3';
import DashboardStats from '@/Pages/partials/DashboardStats.vue';
import RecentMatches from '@/Pages/partials/RecentMatches.vue';
import DeckPerformance from '@/Pages/partials/DeckPerformance.vue';

defineProps<{
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    recentMatches: App.Data.Front.MatchData[];
    deckStats: App.Data.Front.DeckData[];
    timeframe: string;
}>();

usePoll(2000);

const updateTimeframe = (value: string) => {
    router.reload({
        data: {
            timeframe: value,
        },
    });
};
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="grow space-y-4">
            <div class="flex justify-end">
                <NativeSelect :model-value="timeframe" @change="(e) => updateTimeframe(e.target.value)">
                    <option value="week">Last 7 days</option>
                    <option value="biweekly">Last 2 weeks</option>
                    <option value="monthly">Last 30 days</option>
                    <option value="year">This year</option>
                    <option value="alltime">All time</option>
                </NativeSelect>
            </div>

            <DashboardStats
                :matches-won="matchesWon"
                :matches-lost="matchesLost"
                :games-won="gamesWon"
                :games-lost="gamesLost"
                :match-winrate="matchWinrate"
                :game-winrate="gameWinrate"
            />

            <div>
                <Tabs default-value="matches">
                    <TabsList>
                        <TabsTrigger value="matches"> Recent Matches </TabsTrigger>
                        <TabsTrigger value="decks"> Deck Performance </TabsTrigger>
                    </TabsList>
                    <TabsContent value="matches">
                        <RecentMatches :matches="recentMatches" />
                    </TabsContent>
                    <TabsContent value="decks">
                        <DeckPerformance :deck-stats="deckStats" />
                    </TabsContent>
                </Tabs>
            </div>
        </div>
    </AppLayout>
</template>
