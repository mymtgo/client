<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { router } from '@inertiajs/vue3';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';

defineProps<{
    matches: App.Data.Front.MatchData[];
}>();
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardHeader>
            <CardTitle class="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Recent Matches</CardTitle>
        </CardHeader>
        <CardContent class="px-0 pt-0">
            <p v-if="!matches.length" class="px-6 pb-4 text-sm text-muted-foreground">No matches in this timeframe</p>

            <Table v-else>
                <TableHeader class="bg-muted">
                    <TableRow>
                        <TableHead class="w-12"></TableHead>
                        <TableHead>Deck</TableHead>
                        <TableHead>Opponent</TableHead>
                        <TableHead></TableHead>
                        <TableHead>Games</TableHead>
                        <TableHead>When</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="match in matches"
                        :key="match.id"
                        class="cursor-pointer"
                        @click="router.visit(ShowController({ id: match.id }).url)"
                    >
                        <TableCell>
                            <ResultBadge :won="match.gamesWon > match.gamesLost" v-if="match.gamesWon !== match.gamesLost" />
                        </TableCell>
                        <TableCell>
                            <span
                                v-if="match.deck"
                                class="text-primary hover:underline"
                                @click.stop="router.visit(DeckShowController({ deck: match.deck.id }).url)"
                            >
                                {{ match.deck.name }}
                            </span>
                            <span v-else class="text-muted-foreground">Unknown</span>
                        </TableCell>
                        <TableCell>
                            <span v-if="match.opponentArchetypes?.[0]">{{ match.opponentArchetypes[0].archetype.name }}</span>
                            <span v-else class="text-muted-foreground/50">Unknown</span>
                        </TableCell>
                        <TableCell>
                            <ManaSymbols v-if="match.opponentArchetypes?.[0]" :symbols="match.opponentArchetypes[0].archetype.colorIdentity" />
                        </TableCell>
                        <TableCell>{{ match.gamesWon }}-{{ match.gamesLost }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ match.since }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
