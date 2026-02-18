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

// FAKE DATA — replace with props from backend
const matches = [
    {
        id: 101,
        result: 'W',
        deck: { id: 1, name: 'Boros Energy' },
        format: 'Standard',
        opponentName: 'Karadorinn',
        opponentArchetype: 'Dimir Midrange',
        games: '2-0',
        startedAt: '2026-02-17T19:34:00Z',
        leagueGame: true,
    },
    {
        id: 100,
        result: 'L',
        deck: { id: 1, name: 'Boros Energy' },
        format: 'Standard',
        opponentName: 'blisterguy',
        opponentArchetype: 'Azorius Oculus',
        games: '1-2',
        startedAt: '2026-02-17T19:05:00Z',
        leagueGame: true,
    },
    {
        id: 99,
        result: 'W',
        deck: { id: 1, name: 'Boros Energy' },
        format: 'Standard',
        opponentName: 'Patxi_7',
        opponentArchetype: null,
        games: '2-1',
        startedAt: '2026-02-16T21:15:00Z',
        leagueGame: true,
    },
    {
        id: 98,
        result: 'W',
        deck: { id: 2, name: 'Izzet Prowess' },
        format: 'Modern',
        opponentName: 'blisterguy',
        opponentArchetype: 'Burn',
        games: '2-0',
        startedAt: '2026-02-15T14:22:00Z',
        leagueGame: false,
    },
    {
        id: 97,
        result: 'L',
        deck: { id: 2, name: 'Izzet Prowess' },
        format: 'Modern',
        opponentName: 'HammerTime99',
        opponentArchetype: 'Amulet Titan',
        games: '0-2',
        startedAt: '2026-02-15T13:50:00Z',
        leagueGame: false,
    },
    {
        id: 96,
        result: 'W',
        deck: { id: 3, name: 'Mono-Green Devotion' },
        format: 'Pioneer',
        opponentName: 'zuberamaster',
        opponentArchetype: 'Rakdos Midrange',
        games: '2-1',
        startedAt: '2026-02-14T20:10:00Z',
        leagueGame: true,
    },
];
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardHeader class="flex flex-row items-center justify-between px-4 pt-4 pb-2 lg:px-6">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">Recent Matches</CardTitle>
            <Button variant="ghost" size="sm" class="text-xs text-muted-foreground h-auto py-1">View all</Button>
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
                    <TableRow v-for="match in matches" :key="match.id">
                        <TableCell>
                            <Badge
                                :variant="match.result === 'W' ? 'default' : 'destructive'"
                            >
                                {{ match.result === 'W' ? 'Win' : 'Loss' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="font-medium">{{ match.deck.name }}</TableCell>
                        <TableCell>
                            <Badge variant="outline">{{ match.format }}</Badge>
                        </TableCell>
                        <TableCell class="font-medium">{{ match.opponentName }}</TableCell>
                        <TableCell>
                            <span v-if="match.opponentArchetype" class="text-sm">{{ match.opponentArchetype }}</span>
                            <span v-else class="text-muted-foreground text-xs">Unknown</span>
                        </TableCell>
                        <TableCell class="tabular-nums">{{ match.games }}</TableCell>
                        <TableCell class="text-muted-foreground text-xs whitespace-nowrap">
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
