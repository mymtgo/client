<script setup lang="ts">
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import dayjs from 'dayjs';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';

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
</script>

<template>
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
                            <Badge variant="secondary" class="bg-green-500 text-black" v-if="match.gamesWon > match.gamesLost"> Win </Badge>
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
                            <span v-if="!match.opponentArchetypes[0]" class="opacity-50"> Unknown</span>
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

                        <TableCell>
                            <Button size="sm" variant="outline" @click="router.visit(ShowController({ id: match.id }).url)">View</Button>
                        </TableCell>
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
</template>

<style scoped></style>
