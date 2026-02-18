<script setup lang="ts">
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { router, useForm } from '@inertiajs/vue3';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import dayjs from 'dayjs';

defineProps<{
    matches: App.Data.Front.MatchData[];
}>();

const updatePage = (page: number) => {
    router.reload({
        data: {
            page,
        },
    });
};

const deleteForm = useForm<{
    id: string | number;
}>({
    id: '',
});

const deleteMatch = (id: string | number) => {
    deleteForm.id = id;

    deleteForm.submit(DeleteController({ id }), {
        onSuccess: () => deleteForm.reset(),
    });
};
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="px-0">
            <Empty v-if="!matches.total">
                <EmptyDescription>No matches recorded in this timeframe</EmptyDescription>
            </Empty>

            <Table v-if="matches.total">
                <TableHeader class="bg-muted">
                    <TableRow>
                        <TableHead>Result</TableHead>
                        <TableHead>Deck</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Archetype</TableHead>
                        <TableHead></TableHead>
                        <TableHead>Games</TableHead>
                        <TableHead>Duration</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-for="(match, idx) in matches.data" :key="`match_${idx}`">
                        <ContextMenu>
                            <ContextMenuTrigger asChild>
                                <TableRow>
                                    <TableCell>
                                        <Badge variant="default" v-if="match.gamesWon > match.gamesLost"> Win </Badge>
                                        <Badge variant="destructive" v-if="match.gamesWon < match.gamesLost"> Loss </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            v-if="match.deck"
                                            class="cursor-pointer text-primary hover:underline"
                                            @click="router.visit(DeckShowController({ deck: match.deck.id }).url)"
                                        >
                                            {{ match.deck.name }}
                                        </span>
                                        <span v-else class="text-muted-foreground">Unknown</span>
                                    </TableCell>
                                    <TableCell>
                                        <span v-if="match.leagueGame">League</span>
                                        <span v-if="!match.leagueGame">Casual</span>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex items-center gap-1" v-if="match.opponentArchetypes[0]">
                                            {{ match.opponentArchetypes[0].archetype.name }}
                                        </div>
                                        <span v-if="!match.opponentArchetypes[0]" class="opacity-50">Unknown</span>
                                    </TableCell>
                                    <TableCell>
                                        <div v-if="match.opponentArchetypes[0]">
                                            <ManaSymbols :symbols="match.opponentArchetypes[0].archetype.colorIdentity" />
                                        </div>
                                    </TableCell>
                                    <TableCell> {{ match.gamesWon }}-{{ match.gamesLost }} </TableCell>
                                    <TableCell>
                                        {{ match.matchTime }}
                                    </TableCell>
                                    <TableCell>
                                        {{ dayjs(match.startedAt).format('DD/MM/YYYY hh:mma') }}
                                    </TableCell>
                                    <TableCell>
                                        <Button size="sm" variant="outline" @click="router.visit(ShowController({ id: match.id }).url)">View</Button>
                                    </TableCell>
                                </TableRow>
                            </ContextMenuTrigger>
                            <ContextMenuContent>
                                <ContextMenuItem @click="deleteMatch(match.id)">Remove from stats</ContextMenuItem>
                            </ContextMenuContent>
                        </ContextMenu>
                    </template>
                </TableBody>
            </Table>
        </CardContent>

        <div class="justify-end py-2 text-right" v-if="matches.total > 24">
            <Pagination
                @update:page="updatePage"
                v-slot="{ page }"
                :items-per-page="matches.per_page"
                :total="matches.total"
                :default-page="1"
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
</template>
