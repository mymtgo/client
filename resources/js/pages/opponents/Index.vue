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
import { Swords } from 'lucide-vue-next';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

type Opponent = {
    playerId: number;
    username: string;
    matchesWon: number;
    matchesLost: number;
    formats: string[];
    archetypes: { name: string; colorIdentity: string }[];
    lastPlayedAt: string;
};

const props = defineProps<{
    opponents: Opponent[];
}>();

const getTag = (opp: Opponent) => {
    const total = opp.matchesWon + opp.matchesLost;
    if (total < 3) return null;
    const winrate = opp.matchesWon / total;
    if (winrate < 0.4) return 'nemesis';
    if (winrate < 0.5) return 'rival';
    if (winrate > 0.65) return 'favourite_victim';
    return null;
};

const winrate = (opp: Opponent) => {
    const total = opp.matchesWon + opp.matchesLost;
    return total === 0 ? 0 : Math.round((opp.matchesWon / total) * 100);
};

const allFormats = computed(() =>
    [...new Set(props.opponents.flatMap((o) => o.formats))].sort()
);

const search = ref('');
const sortBy = ref('winrate_desc');
const selectedFormat = ref<string | null>(null);

const filtered = computed(() => {
    let list = [...props.opponents];

    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        list = list.filter((o) => o.username.toLowerCase().includes(q));
    }

    if (selectedFormat.value) {
        list = list.filter((o) => o.formats.includes(selectedFormat.value!));
    }

    list.sort((a, b) => {
        switch (sortBy.value) {
            case 'winrate_asc':  return winrate(a) - winrate(b);
            case 'winrate_desc': return winrate(b) - winrate(a);
            case 'most_recent':  return dayjs(b.lastPlayedAt).diff(dayjs(a.lastPlayedAt));
            default:             return (b.matchesWon + b.matchesLost) - (a.matchesWon + a.matchesLost);
        }
    });

    return list;
});
</script>

<template>
    <AppLayout title="Opponents">
        <div class="flex flex-col gap-4 p-3 lg:p-4">

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
                                <TableCell colspan="5" class="py-12 text-center">
                                    <div class="flex flex-col items-center gap-2">
                                        <Swords class="size-10 text-muted-foreground/40" />
                                        <p class="font-medium">No opponents found</p>
                                        <p class="text-sm text-muted-foreground">Play some matches and your opponents will show up here.</p>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableRow
                            v-for="opp in filtered"
                            :key="opp.playerId"
                        >
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ opp.username }}</span>
                                    <Badge
                                        v-if="getTag(opp) === 'nemesis'"
                                        variant="destructive"
                                        class="text-xs"
                                    >Nemesis</Badge>
                                    <Badge
                                        v-else-if="getTag(opp) === 'rival'"
                                        variant="secondary"
                                    >Rival</Badge>
                                    <Badge
                                        v-else-if="getTag(opp) === 'favourite_victim'"
                                        variant="default"
                                    >Favourite Victim</Badge>
                                </div>
                            </TableCell>
                            <TableCell>
                                <TooltipProvider v-if="opp.archetypes.length > 0">
                                    <div class="flex flex-wrap gap-1">
                                        <Tooltip v-for="archetype in opp.archetypes" :key="archetype.name">
                                            <TooltipTrigger>
                                                <div class="flex items-center gap-1 border px-2 py-0.5">
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
                                :class="winrate(opp) < 50 ? 'text-destructive' : ''"
                            >
                                {{ winrate(opp) }}%
                            </TableCell>
                            <TableCell class="text-muted-foreground whitespace-nowrap text-sm">
                                {{ dayjs(opp.lastPlayedAt).fromNow() }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </Card>
        </div>
    </AppLayout>
</template>
