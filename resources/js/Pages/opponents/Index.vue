<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ManaSymbols from '@/components/ManaSymbols.vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

defineProps<{
    opponents?: any[];
}>();

// FAKE DATA — replace with props from backend
const allOpponents = [
    {
        username: 'blisterguy',
        matchesWon: 3, matchesLost: 8, winrate: 27,
        formats: ['Standard', 'Modern'],
        lastPlayedAt: '2026-02-17T19:05:00Z',
        archetypes: [
            { name: 'Azorius Oculus', colorIdentity: 'W,U' },
            { name: 'Dimir Midrange', colorIdentity: 'U,B' },
        ],
    },
    {
        username: 'Karadorinn',
        matchesWon: 7, matchesLost: 5, winrate: 58,
        formats: ['Standard'],
        lastPlayedAt: '2026-02-17T19:34:00Z',
        archetypes: [
            { name: 'Dimir Midrange', colorIdentity: 'U,B' },
        ],
    },
    {
        username: 'zuberamaster',
        matchesWon: 8, matchesLost: 2, winrate: 80,
        formats: ['Pioneer', 'Standard'],
        lastPlayedAt: '2026-02-14T20:10:00Z',
        archetypes: [
            { name: 'Rakdos Midrange', colorIdentity: 'B,R' },
            { name: 'Mono-Green Devotion', colorIdentity: 'G' },
        ],
    },
    {
        username: 'Patxi_7',
        matchesWon: 4, matchesLost: 2, winrate: 67,
        formats: ['Standard'],
        lastPlayedAt: '2026-02-16T21:15:00Z',
        archetypes: [],
    },
    {
        username: 'HammerTime99',
        matchesWon: 2, matchesLost: 3, winrate: 40,
        formats: ['Modern'],
        lastPlayedAt: '2026-02-15T13:50:00Z',
        archetypes: [
            { name: 'Hammer Time', colorIdentity: 'W' },
        ],
    },
    {
        username: 'mtggrinder42',
        matchesWon: 5, matchesLost: 4, winrate: 56,
        formats: ['Standard'],
        lastPlayedAt: '2026-02-10T18:30:00Z',
        archetypes: [
            { name: 'Temur Oculus', colorIdentity: 'G,U' },
        ],
    },
    {
        username: 'DraftKing99',
        matchesWon: 1, matchesLost: 3, winrate: 25,
        formats: ['Modern'],
        lastPlayedAt: '2026-01-05T12:00:00Z',
        archetypes: [
            { name: 'Amulet Titan', colorIdentity: 'G' },
        ],
    },
    {
        username: 'CubeSlinger',
        matchesWon: 1, matchesLost: 1, winrate: 50,
        formats: ['Legacy'],
        lastPlayedAt: '2026-01-20T15:00:00Z',
        archetypes: [
            { name: 'Reanimator', colorIdentity: 'U,B' },
        ],
    },
];

// Tag logic — min 3 matches required
const getTag = (opp: typeof allOpponents[0]) => {
    const total = opp.matchesWon + opp.matchesLost;
    if (total < 3) return null;
    if (opp.winrate < 40) return 'nemesis';
    if (opp.winrate < 50) return 'rival';
    if (opp.winrate > 65) return 'favourite_victim';
    return null;
};

// All formats across fake data
const allFormats = [...new Set(allOpponents.flatMap((o) => o.formats))].sort();

// Toolbar state
const search = ref('');
const sortBy = ref('most_played');
const selectedFormat = ref<string | null>(null);

const filtered = computed(() => {
    let list = [...allOpponents];

    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        list = list.filter((o) => o.username.toLowerCase().includes(q));
    }

    if (selectedFormat.value) {
        list = list.filter((o) => o.formats.includes(selectedFormat.value!));
    }

    list.sort((a, b) => {
        switch (sortBy.value) {
            case 'winrate_asc':  return a.winrate - b.winrate;
            case 'winrate_desc': return b.winrate - a.winrate;
            case 'most_recent':  return dayjs(b.lastPlayedAt).diff(dayjs(a.lastPlayedAt));
            default:             return (b.matchesWon + b.matchesLost) - (a.matchesWon + a.matchesLost);
        }
    });

    return list;
});
</script>

<template>
    <AppLayout title="Opponents">
        <div class="flex flex-col gap-4 p-4 lg:p-6">

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-3">
                <Input
                    v-model="search"
                    placeholder="Search opponents..."
                    class="w-56"
                />

                <Select v-model="sortBy">
                    <SelectTrigger class="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="most_played">Most played</SelectItem>
                        <SelectItem value="winrate_asc">Win rate ↑ (worst first)</SelectItem>
                        <SelectItem value="winrate_desc">Win rate ↓ (best first)</SelectItem>
                        <SelectItem value="most_recent">Most recent</SelectItem>
                    </SelectContent>
                </Select>

                <!-- Format pills -->
                <div class="flex items-center gap-1.5">
                    <Button
                        size="sm"
                        :variant="selectedFormat === null ? 'default' : 'outline'"
                        @click="selectedFormat = null"
                    >All</Button>
                    <Button
                        v-for="format in allFormats"
                        :key="format"
                        size="sm"
                        :variant="selectedFormat === format ? 'default' : 'outline'"
                        @click="selectedFormat = format"
                    >{{ format }}</Button>
                </div>
            </div>

            <!-- Opponents table -->
            <Card class="overflow-hidden">
                <Table>
                    <TableHeader class="bg-muted">
                        <TableRow>
                            <TableHead>Opponent</TableHead>
                            <TableHead>Archetypes Seen</TableHead>
                            <TableHead>W/L</TableHead>
                            <TableHead>Win Rate</TableHead>
                            <TableHead>Last Played</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="filtered.length === 0">
                            <TableRow>
                                <TableCell colspan="5" class="py-12 text-center text-muted-foreground text-sm">
                                    No opponents found.
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableRow
                            v-for="opp in filtered"
                            :key="opp.username"
                            class="cursor-pointer"
                        >
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ opp.username }}</span>
                                    <Badge
                                        v-if="getTag(opp) === 'nemesis'"
                                        variant="destructive"
                                        class="text-xs"
                                    >👹 Nemesis</Badge>
                                    <Badge
                                        v-else-if="getTag(opp) === 'rival'"
                                        class="border-transparent bg-amber-500 text-white text-xs"
                                    >⚔️ Rival</Badge>
                                    <Badge
                                        v-else-if="getTag(opp) === 'favourite_victim'"
                                        class="border-transparent bg-win text-win-foreground text-xs"
                                    >🎯 Favourite Victim</Badge>
                                </div>
                            </TableCell>
                            <TableCell>
                                <TooltipProvider v-if="opp.archetypes.length > 0">
                                    <div class="flex flex-wrap gap-1">
                                        <Tooltip v-for="archetype in opp.archetypes" :key="archetype.name">
                                            <TooltipTrigger>
                                                <div class="flex items-center gap-1 rounded-full border px-2 py-0.5">
                                                    <ManaSymbols :symbols="archetype.colorIdentity" />
                                                </div>
                                            </TooltipTrigger>
                                            <TooltipContent>{{ archetype.name }}</TooltipContent>
                                        </Tooltip>
                                    </div>
                                </TooltipProvider>
                                <span v-else class="text-muted-foreground text-sm">Unknown</span>
                            </TableCell>
                            <TableCell class="tabular-nums">
                                {{ opp.matchesWon }}W – {{ opp.matchesLost }}L
                            </TableCell>
                            <TableCell
                                class="font-semibold tabular-nums"
                                :class="opp.winrate >= 50 ? 'text-win' : 'text-destructive'"
                            >
                                {{ opp.winrate }}%
                            </TableCell>
                            <TableCell class="text-muted-foreground text-sm whitespace-nowrap">
                                {{ dayjs(opp.lastPlayedAt).fromNow() }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </Card>
        </div>
    </AppLayout>
</template>
