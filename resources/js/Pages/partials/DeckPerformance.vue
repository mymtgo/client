<script setup lang="ts">
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { router } from '@inertiajs/vue3';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';

defineProps<{
    deckStats: App.Data.Front.DeckData[];
}>();
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="px-0">
            <Empty v-if="!deckStats.length">
                <EmptyDescription>No decks with matches in this timeframe</EmptyDescription>
            </Empty>

            <Table v-if="deckStats.length">
                <TableHeader class="bg-muted">
                    <TableRow>
                        <TableHead>Deck</TableHead>
                        <TableHead>Format</TableHead>
                        <TableHead>Matches</TableHead>
                        <TableHead>W-L</TableHead>
                        <TableHead>Winrate</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="deck in deckStats" :key="`deck_${deck.id}`">
                        <TableCell class="font-medium">
                            {{ deck.name }}
                        </TableCell>
                        <TableCell>
                            <Badge variant="outline">{{ deck.format }}</Badge>
                        </TableCell>
                        <TableCell>
                            {{ deck.matchesCount }}
                        </TableCell>
                        <TableCell>
                            <span class="text-win">{{ deck.matchesWon }}</span>-<span class="text-loss">{{ deck.matchesLost }}</span>
                        </TableCell>
                        <TableCell>
                            <span :class="deck.winrate >= 50 ? 'text-win' : 'text-loss'">{{ deck.winrate }}%</span>
                        </TableCell>
                        <TableCell>
                            <Button size="sm" variant="outline" @click="router.visit(DeckShowController({ deck: deck.id }).url)">View</Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
