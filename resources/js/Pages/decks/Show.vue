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
    matches: App.Data.Front.MatchData[];
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
                        <CardContent class="text-xl"> {{ gameWinrate }}% OTP</CardContent>
                    </Card>
                </div>

                <div>
                    <Tabs default-value="matches">
                        <TabsList>
                            <TabsTrigger value="matches"> Matches </TabsTrigger>
                            <TabsTrigger value="matchupSpread"> Matchup spread </TabsTrigger>
                        </TabsList>
                        <TabsContent value="matches">
                            <DeckMatches :matches="matches" />
                        </TabsContent>
                        <TabsContent value="matchupSpread">
                            <MatchupSpread :matchupSpread="matchupSpread" />
                        </TabsContent>
                    </Tabs>
                </div>
            </div>

            <div class="col-span-3 px-8">
                <div>
                    <header>Maindeck</header>

                    <div class="mt-2 space-y-2">
                        <section v-for="(cards, type) in maindeck" :key="`group_${type}`" class="space-y-1">
                            <header class="text-sm font-semibold text-sidebar-foreground/70">{{ type }} ({{ cards.length }})</header>
                            <ul class="space-y-1">
                                <li v-for="card in cards" :key="`card_${card.id}`">
                                    <HoverCard>
                                        <HoverCardTrigger>
                                            <div>
                                                <Badge variant="secondary">{{ card.quantity }}</Badge> {{ card.name }}
                                            </div>
                                        </HoverCardTrigger>
                                        <HoverCardContent side="right" avoidCollisions class="overflow-hidden rounded-xl p-0">
                                            <img :src="card.image" class="w-64" />
                                        </HoverCardContent>
                                    </HoverCard>
                                </li>
                            </ul>
                        </section>
                    </div>
                    <header class="my-2">Sideboard</header>
                    <ul class="space-y-1">
                        <li v-for="card in sideboard" :key="`card_${card.id}`">
                            <HoverCard>
                                <HoverCardTrigger>
                                    <div>
                                        <Badge variant="secondary">{{ card.quantity }}</Badge> {{ card.name }}
                                    </div>
                                </HoverCardTrigger>
                                <HoverCardContent side="right" avoidCollisions class="overflow-hidden rounded-xl p-0">
                                    <img :src="card.image" class="w-64" />
                                </HoverCardContent>
                            </HoverCard>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped></style>
