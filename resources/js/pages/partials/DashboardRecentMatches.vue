<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import ShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

type Paginator<T> = { data: T[] };

const props = defineProps<{
    matches: Paginator<App.Data.Front.MatchData>;
}>();
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardHeader class="flex flex-row items-center justify-between px-4 pt-4 pb-2 lg:px-6">
            <CardTitle class="text-sm font-medium tracking-wide text-muted-foreground uppercase">Recent Matches</CardTitle>
            <Button variant="ghost" size="sm" class="h-auto py-1 text-xs text-muted-foreground">View all</Button>
        </CardHeader>

        <CardContent class="px-0 pb-0">
            <Table>
                <TableHeader class="bg-muted">
                    <TableRow>
                        <TableHead>Result</TableHead>
                        <TableHead>Deck</TableHead>
                        <TableHead>Format</TableHead>
                        <TableHead>Opponent</TableHead>
                        <TableHead>Archetype</TableHead>
                        <TableHead>Games</TableHead>
                        <TableHead>When</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-if="matches.data.length === 0">
                        <TableCell colspan="8" class="py-8 text-center text-sm text-muted-foreground">
                            No matches in this timeframe.
                        </TableCell>
                    </TableRow>
                    <TableRow v-for="match in matches.data" :key="match.id">
                        <TableCell>
                            <Badge :variant="match.result === 'won' ? 'default' : 'destructive'">
                                {{ match.result === 'won' ? 'Win' : 'Loss' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="font-medium">{{ match.deck?.name ?? '—' }}</TableCell>
                        <TableCell>
                            <Badge variant="outline">{{ match.format }}</Badge>
                        </TableCell>
                        <TableCell class="font-medium">{{ match.opponentName ?? '—' }}</TableCell>
                        <TableCell>
                            <span v-if="match.opponentArchetypes?.[0]?.archetype?.name" class="text-sm">
                                {{ match.opponentArchetypes[0].archetype.name }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground">Unknown</span>
                        </TableCell>
                        <TableCell class="tabular-nums">{{ match.gamesWon }}-{{ match.gamesLost }}</TableCell>
                        <TableCell class="text-xs whitespace-nowrap text-muted-foreground">
                            {{ dayjs(match.startedAt).fromNow() }}
                        </TableCell>
                        <TableCell>
                            <Button size="sm" variant="ghost" @click="router.visit(ShowController({ id: match.id }).url)">View</Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
