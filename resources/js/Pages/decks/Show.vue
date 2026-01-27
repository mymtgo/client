<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { NativeSelect } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { home } from '@/routes';
import { router, usePoll } from '@inertiajs/vue3';
import MatchupSpread from '@/Pages/decks/partials/MatchupSpread.vue';
import DeckMatches from '@/Pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/Pages/decks/partials/DeckLeagues.vue';
import DeckList from '@/Pages/decks/partials/DeckList.vue';
import MatchHistoryChart from '@/Pages/decks/partials/MatchHistoryChart.vue';

defineProps<{
    matchupSpread: any[];
    deck: App.Data.Front.DeckData;
    maindeck: App.Data.Front.CardData[];
    sideboard: App.Data.Front.CardData[];
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    gameWinrateOtp: number;
    gamesOtd: number;
    gamesOtdWon: number;
    gamesOtdLost: number;
    gamesOtp: number;
    gamesOtpWon: number;
    gamesOtpLost: number;
    otpRate: number;
    matches: App.Data.Front.MatchData[];
    leagues: App.Data.Front.LeagueData[];
    matchChartData: any[];
}>();

usePoll(2000);
</script>

<template>
    <AppLayout :back="home().url" :title="deck.name">
        <div class="grid grow grid-cols-12 text-white">
            <div class="col-span-9 grow space-y-2">
                <div class="flex justify-end">
                    <NativeSelect model-value="7days">
                        <option value="7days">Last 7 days</option>
                        <option value="biweekly">Last 2 weeks</option>
                        <option value="monthly">Last 30 days</option>
                        <option value="year">This year</option>
                    </NativeSelect>
                </div>

                <div class="grid grid-cols-4 gap-4">
                    <Card class="gap-0">
                        <CardHeader>Total Matches</CardHeader>
                        <CardContent class="text-2xl">
                            {{ matchesWon }}-{{ matchesLost }} <span class="text-white/40">({{ matchesWon + matchesLost }})</span></CardContent
                        >
                    </Card>
                    <Card class="gap-0">
                        <CardHeader>Match winrate</CardHeader>
                        <CardContent class="text-2xl"> {{ matchWinrate }}% </CardContent>
                    </Card>
                    <Card class="gap-0">
                        <CardHeader>Total Games</CardHeader>
                        <CardContent class="text-2xl">
                            {{ gamesWon }}-{{ gamesLost }} <span class="text-white/40">({{ gamesWon + gamesLost }})</span>
                        </CardContent>
                    </Card>
                    <Card class="gap-0">
                        <CardHeader>Game Winrate</CardHeader>
                        <CardContent class="text-xl"> {{ gameWinrate }}%</CardContent>
                    </Card>
                </div>

                <MatchHistoryChart :data="matchChartData" />

                <div class="grid grid-cols-4 gap-4">
                    <Card class="gap-0">
                        <CardHeader>OTP W/L</CardHeader>
                        <CardContent class="text-xl"> {{ gamesOtpWon }}-{{ gamesOtpLost }}</CardContent>
                    </Card>
                    <Card class="gap-0">
                        <CardHeader>OTD W/L</CardHeader>
                        <CardContent class="text-xl"> {{ gamesOtdWon }}-{{ gamesOtdLost }}</CardContent>
                    </Card>
                    <Card class="gap-0">
                        <CardHeader>OTP %</CardHeader>
                        <CardContent class="text-xl"> {{ otpRate }}%</CardContent>
                    </Card>
                </div>

                <div>
                    <Tabs default-value="leagues">
                        <TabsList>
                            <TabsTrigger value="leagues"> Leagues </TabsTrigger>
                            <TabsTrigger value="matches"> Matches </TabsTrigger>
                            <TabsTrigger value="matchupSpread"> Matchup spread </TabsTrigger>
                        </TabsList>
                        <TabsContent value="leagues">
                            <DeckLeagues :leagues="leagues" />
                        </TabsContent>
                        <TabsContent value="matches">
                            <DeckMatches :matches="matches" />
                        </TabsContent>
                        <TabsContent value="matchupSpread">
                            <MatchupSpread :matchupSpread="matchupSpread" />
                        </TabsContent>
                    </Tabs>
                </div>
            </div>

            <div class="col-span-3 no-scrollbar h-screen overflow-y-auto px-8">
                <DeckList :maindeck="maindeck" :sideboard="sideboard" />
            </div>
        </div>
    </AppLayout>
</template>

<style scoped></style>
