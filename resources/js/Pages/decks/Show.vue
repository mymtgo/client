<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { NativeSelect } from '@/components/ui/native-select';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { home } from '@/routes';
import { router, usePoll } from '@inertiajs/vue3';
import dayjs from 'dayjs';

const props = defineProps<{
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
    matches: App.Data.Front.MatchData[];
}>();

usePoll(2000);

const updatePage = (page: number) => {
    router.reload({
        data: {
            page,
        },
    });
};
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
                        <CardContent class="text-2xl"> {{ gameWinrate }}% </CardContent>
                    </Card>
                </div>
                <div>
                    <Card class="gap-0 overflow-hidden pb-0">
                        <CardHeader>
                            <h2 class="font-medium">Matchup performance</h2>

                            <div></div>
                        </CardHeader>
                        <CardContent class="p-4 px-0">
                            <Empty v-if="!matchupSpread.length">
                                <EmptyDescription> Archetype matchup data will appear as you play matches. </EmptyDescription>
                            </Empty>

                            <Table v-if="matchupSpread.length">
                                <TableHeader class="bg-muted">
                                    <TableRow>
                                        <TableHead>Archetype</TableHead>
                                        <TableHead></TableHead>
                                        <TableHead>Matches</TableHead>
                                        <TableHead>Match winrate</TableHead>
                                        <TableHead>Games</TableHead>
                                        <TableHead>Game winrate</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="(matchup, idx) in matchupSpread" :key="`matchup_${idx}`">
                                        <TableCell>
                                            {{ matchup.name }}
                                        </TableCell>
                                        <TableCell>
                                            <ManaSymbols :symbols="matchup.color_identity" />
                                        </TableCell>
                                        <TableCell> {{ matchup.match_record }}</TableCell>
                                        <TableCell class="">
                                            <span
                                                :class="{
                                                    'text-red-500': !matchup.match_winrate,
                                                    'text-orange-500': matchup.match_winrate && matchup.match_winrate < 50,
                                                    'text-green-500': matchup.match_winrate >= 50,
                                                }"
                                            >
                                                {{ matchup.match_winrate }}%
                                            </span>
                                        </TableCell>
                                        <TableCell> {{ matchup.game_record }}</TableCell>
                                        <TableCell>
                                            <span
                                                :class="{
                                                    'text-red-500': !matchup.game_winrate,
                                                    'text-orange-500': matchup.game_winrate && matchup.game_winrate < 50,
                                                    'text-green-500': matchup.game_winrate >= 50,
                                                }"
                                            >
                                                {{ matchup.game_winrate }}%
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                <Card class="gap-0 overflow-hidden pb-0">
                    <CardHeader>
                        <div class="flex justify-between">
                            <h2 class="font-medium">Matches</h2>

                            <div class="text-sm text-sidebar-foreground/70" v-if="matches.total">
                                Showing {{ matches.from || '0' }} to {{ matches.to || '0' }} of {{ matches.total }}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="px-0 pt-4">
                        <Empty v-if="!matches.total">
                            <EmptyDescription>No matches recorded</EmptyDescription>
                        </Empty>

                        <Table v-if="matches.total">
                            <TableHeader class="bg-muted">
                                <TableRow>
                                    <TableHead>Result</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Opponent archetype</TableHead>
                                    <TableHead>Games won</TableHead>
                                    <TableHead>Games lost</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="(match, idx) in matches.data" :key="`match_${idx}`">
                                    <TableCell>
                                        <Badge variant="secondary" class="bg-green-500 text-black" v-if="match.gamesWon > match.gamesLost">
                                            Win
                                        </Badge>
                                        <Badge variant="destructive" v-if="match.gamesWon < match.gamesLost"> Loss </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <span v-if="match.leagueGame">League</span>
                                        <span v-if="!match.leagueGame">Casual</span>
                                    </TableCell>
                                    <TableCell>
                                        <span v-if="match.opponentArchetypes[0]">
                                            {{ match.opponentArchetypes[0].archetype.name }}
                                        </span>
                                        <span v-if="!match.opponentArchetypes[0]" class="opacity-50"> Unknown </span>
                                    </TableCell>
                                    <TableCell>
                                        {{ match.gamesWon }}
                                    </TableCell>
                                    <TableCell>
                                        {{ match.gamesLost }}
                                    </TableCell>
                                    <TableCell>
                                        {{ match.matchTime }}
                                    </TableCell>
                                    <TableCell>
                                        {{ dayjs(match.startedAt).format('DD/MM/YYYY hh:mma') }}
                                    </TableCell>

                                    <TableCell> View </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>

                    <div class="justify-end py-2 text-right">
                        <Pagination
                            @update:page="updatePage"
                            v-slot="{ page }"
                            :items-per-page="matches.per_page"
                            :total="matches.total"
                            :default-page="1"
                            v-if="matches.total > 1"
                        >
                            <PaginationContent v-slot="{ items }">
                                <PaginationPrevious />
                                <template v-for="(item, index) in items" :key="index">
                                    <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page">
                                        {{ item.value }}
                                    </PaginationItem>
                                </template>
                                <PaginationNext />
                            </PaginationContent>
                        </Pagination>
                    </div>
                </Card>
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
