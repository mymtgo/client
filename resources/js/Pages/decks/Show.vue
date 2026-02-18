<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { home } from '@/routes';
import MatchupSpread from '@/Pages/decks/partials/MatchupSpread.vue';
import DeckMatches from '@/Pages/decks/partials/DeckMatches.vue';
import DeckLeagues from '@/Pages/decks/partials/DeckLeagues.vue';
import DeckList from '@/Pages/decks/partials/DeckList.vue';

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
    archetypes: App.Data.Front.ArchetypeData[];
}>();

</script>

<template>
    <AppLayout :back="home().url" :title="deck.name">
        <div class="grid grow grid-cols-12">
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
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>Total Matches</CardDescription>
                        </CardHeader>
                        <CardContent class="text-2xl font-semibold">
                            {{ matchesWon }}-{{ matchesLost }} <span class="text-muted-foreground text-sm font-normal">({{ matchesWon + matchesLost }})</span>
                        </CardContent>
                    </Card>
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>Match Winrate</CardDescription>
                        </CardHeader>
                        <CardContent class="text-2xl font-semibold">{{ matchWinrate }}%</CardContent>
                    </Card>
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>Total Games</CardDescription>
                        </CardHeader>
                        <CardContent class="text-2xl font-semibold">
                            {{ gamesWon }}-{{ gamesLost }} <span class="text-muted-foreground text-sm font-normal">({{ gamesWon + gamesLost }})</span>
                        </CardContent>
                    </Card>
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>Game Winrate</CardDescription>
                        </CardHeader>
                        <CardContent class="text-2xl font-semibold">{{ gameWinrate }}%</CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-4 gap-4">
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>OTP W/L</CardDescription>
                        </CardHeader>
                        <CardContent class="text-xl font-semibold">{{ gamesOtpWon }}-{{ gamesOtpLost }}</CardContent>
                    </Card>
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>OTD W/L</CardDescription>
                        </CardHeader>
                        <CardContent class="text-xl font-semibold">{{ gamesOtdWon }}-{{ gamesOtdLost }}</CardContent>
                    </Card>
                    <Card class="gap-2">
                        <CardHeader>
                            <CardDescription>OTP %</CardDescription>
                        </CardHeader>
                        <CardContent class="text-xl font-semibold">{{ otpRate }}%</CardContent>
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
                            <DeckLeagues :leagues="leagues" :archetypes="archetypes" />
                        </TabsContent>
                        <TabsContent value="matches">
                            <DeckMatches :matches="matches" :archetypes="archetypes" />
                        </TabsContent>
                        <TabsContent value="matchupSpread">
                            <MatchupSpread :matchupSpread="matchupSpread" />
                        </TabsContent>

                    </Tabs>
                </div>
            </div>

            <div class="col-span-3 sticky top-0 max-h-screen overflow-y-auto no-scrollbar px-8">
                <DeckList :maindeck="maindeck" :sideboard="sideboard" />
            </div>
        </div>
    </AppLayout>
</template>
