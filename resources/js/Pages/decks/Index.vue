<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyTitle } from '@/components/ui/empty';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/vue3';
import ShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { ChevronDown, Layers } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

// FAKE DATA — replace with props from backend
type DeckEntry = {
    id: number;
    name: string;
    format: string;
    winRate: number;
    matchesWon: number;
    matchesLost: number;
    lastPlayedAt: string;
};

const allDecks: DeckEntry[] = [
    { id: 1, name: 'Boros Energy',         format: 'Standard', winRate: 68, matchesWon: 23, matchesLost: 11, lastPlayedAt: '2026-02-17T19:34:00Z' },
    { id: 2, name: 'Dimir Control',         format: 'Standard', winRate: 52, matchesWon: 10, matchesLost:  9, lastPlayedAt: '2026-01-30T14:20:00Z' },
    { id: 3, name: 'Jeskai Convoke',        format: 'Standard', winRate: 44, matchesWon:  7, matchesLost:  9, lastPlayedAt: '2025-11-14T10:00:00Z' },
    { id: 4, name: 'Izzet Prowess',         format: 'Modern',   winRate: 61, matchesWon: 16, matchesLost: 10, lastPlayedAt: '2026-02-15T14:22:00Z' },
    { id: 5, name: 'Amulet Titan',          format: 'Modern',   winRate: 45, matchesWon:  5, matchesLost:  6, lastPlayedAt: '2025-12-10T21:00:00Z' },
    { id: 6, name: 'Mono-Green Devotion',   format: 'Pioneer',  winRate: 57, matchesWon: 12, matchesLost:  9, lastPlayedAt: '2026-02-14T20:10:00Z' },
    { id: 7, name: 'Rakdos Midrange',       format: 'Pioneer',  winRate: 63, matchesWon: 19, matchesLost: 11, lastPlayedAt: '2026-01-05T15:00:00Z' },
    { id: 8, name: 'Azorius Control',       format: 'Pioneer',  winRate: 36, matchesWon:  5, matchesLost:  9, lastPlayedAt: '2025-10-22T18:30:00Z' },
];

const formats = [...new Set(allDecks.map((d) => d.format))];

const activeFormat = ref<string>('All');
const sortBy = ref<'lastPlayed' | 'winRate' | 'matchCount' | 'name'>('lastPlayed');

const sortLabel = computed(() => ({
    lastPlayed: 'Last Played',
    winRate:    'Win Rate',
    matchCount: 'Match Count',
    name:       'Name',
}[sortBy.value]));

const sortedDecks = (decks: DeckEntry[]) => {
    return [...decks].sort((a, b) => {
        switch (sortBy.value) {
            case 'lastPlayed':  return dayjs(b.lastPlayedAt).diff(dayjs(a.lastPlayedAt));
            case 'winRate':     return b.winRate - a.winRate;
            case 'matchCount':  return (b.matchesWon + b.matchesLost) - (a.matchesWon + a.matchesLost);
            case 'name':        return a.name.localeCompare(b.name);
        }
    });
};

const visibleFormats = computed(() => {
    const filter = activeFormat.value;
    return formats
        .filter((f) => filter === 'All' || f === filter)
        .map((format) => {
            const decks = sortedDecks(allDecks.filter((d) => d.format === format));
            const totalWins   = decks.reduce((s, d) => s + d.matchesWon, 0);
            const totalLosses = decks.reduce((s, d) => s + d.matchesLost, 0);
            const total       = totalWins + totalLosses;
            const avgWinRate  = total > 0 ? Math.round((totalWins / total) * 100) : 0;
            return { format, decks, avgWinRate };
        });
});
</script>

<template>
    <AppLayout title="Decks">
        <div class="flex flex-col gap-6 p-4 lg:p-6">

            <!-- Toolbar -->
            <div class="flex items-center gap-2 flex-wrap">
                <!-- Format pills -->
                <div class="flex items-center gap-1.5 flex-wrap">
                    <Button
                        v-for="f in ['All', ...formats]"
                        :key="f"
                        size="sm"
                        :variant="activeFormat === f ? 'default' : 'outline'"
                        @click="activeFormat = f"
                    >
                        {{ f }}
                    </Button>
                </div>

                <!-- Sort -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="ml-auto gap-1.5">
                            {{ sortLabel }}
                            <ChevronDown class="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuRadioGroup v-model="sortBy">
                            <DropdownMenuRadioItem value="lastPlayed">Last Played</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="winRate">Win Rate</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="matchCount">Match Count</DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="name">Name</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- Empty state -->
            <Empty v-if="allDecks.length === 0">
                <EmptyTitle>No decks yet</EmptyTitle>
                <EmptyDescription>Decks are synced automatically from MTGO once the file watcher is running.</EmptyDescription>
            </Empty>

            <!-- Format sections -->
            <template v-for="{ format, decks, avgWinRate } in visibleFormats" :key="format">
                <div class="flex flex-col gap-3">
                    <!-- Section header -->
                    <div class="flex items-baseline gap-3">
                        <h2 class="text-sm font-semibold">{{ format }}</h2>
                        <span class="text-xs text-muted-foreground">avg {{ avgWinRate }}% win rate</span>
                        <div class="flex-1 border-t border-border self-center ml-1" />
                    </div>

                    <!-- Deck cards grid -->
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Card
                            v-for="deck in decks"
                            :key="deck.id"
                            class="cursor-pointer transition-colors hover:bg-accent"
                            @click="router.visit(ShowController({ deck: deck.id }).url)"
                        >
                            <CardContent class="flex flex-col gap-3 p-4">
                                <!-- Name + meta -->
                                <div class="flex flex-col gap-1">
                                    <span class="font-semibold leading-tight">{{ deck.name }}</span>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Badge variant="outline" class="text-xs py-0">{{ deck.format }}</Badge>
                                        <span>·</span>
                                        <span>Last played {{ dayjs(deck.lastPlayedAt).fromNow() }}</span>
                                    </div>
                                </div>

                                <!-- Stats -->
                                <div class="flex items-end justify-between">
                                    <div class="flex flex-col">
                                        <span
                                            class="text-3xl font-bold tabular-nums leading-none"
                                            :class="deck.winRate >= 50 ? 'text-win' : 'text-destructive'"
                                        >
                                            {{ deck.winRate }}%
                                        </span>
                                        <span class="text-xs text-muted-foreground mt-0.5">win rate</span>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium tabular-nums">
                                            {{ deck.matchesWon + deck.matchesLost }} matches
                                        </div>
                                        <div class="text-xs text-muted-foreground tabular-nums">
                                            <span class="text-win">{{ deck.matchesWon }}W</span>
                                            <span class="mx-0.5">–</span>
                                            <span class="text-destructive">{{ deck.matchesLost }}L</span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </template>

        </div>
    </AppLayout>
</template>
