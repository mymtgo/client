<script setup lang="ts">
import { ref, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import ManaSymbols from '@/components/ManaSymbols.vue';
import WinRateBar from '@/components/WinRateBar.vue';
import { Skull, Swords } from 'lucide-vue-next';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

type Opponent = {
    playerId: number;
    username: string;
    matchesWon: number;
    matchesLost: number;
    formats: string[];
    archetypes: { name: string; colorIdentity: string | null }[];
    lastPlayedAt: string;
};

const props = defineProps<{
    opponents: Opponent[];
}>();

const VISIBLE_ARCHETYPES = 3;
const PER_PAGE = 25;

const getTag = (opp: Opponent): 'nemesis' | 'rival' | null => {
    const total = opp.matchesWon + opp.matchesLost;
    if (total < 2) return null;
    const wr = Math.round((opp.matchesWon / total) * 100);
    if (wr <= 39) return 'nemesis';
    if (wr <= 60) return 'rival';
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
const currentPage = ref(1);

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

const totalPages = computed(() => Math.ceil(filtered.value.length / PER_PAGE));
const paginated = computed(() => {
    const start = (currentPage.value - 1) * PER_PAGE;
    return filtered.value.slice(start, start + PER_PAGE);
});

// Reset to page 1 when filters change
import { watch } from 'vue';
watch([search, sortBy, selectedFormat], () => { currentPage.value = 1; });
</script>

<template>
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

                <div class="ml-auto flex items-center gap-1.5">
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
                            <TableHead class="w-0"></TableHead>
                            <TableHead>Opponent</TableHead>
                            <TableHead>Archetypes Seen</TableHead>
                            <TableHead>Record</TableHead>
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
                            v-for="opp in paginated"
                            :key="opp.playerId"
                        >
                            <TableCell>
                                <TooltipProvider v-if="getTag(opp)">
                                    <Tooltip>
                                        <TooltipTrigger>
                                            <span
                                                v-if="getTag(opp) === 'nemesis'"
                                                class="inline-flex items-center gap-1 rounded-full border border-red-500/50 px-2 py-0.5 text-xs font-medium text-red-500"
                                            >
                                                <Skull class="size-3" />
                                                Nemesis
                                            </span>
                                            <span
                                                v-else-if="getTag(opp) === 'rival'"
                                                class="inline-flex items-center gap-1 rounded-full border border-indigo-500/50 px-2 py-0.5 text-xs font-medium text-indigo-500"
                                            >
                                                <Swords class="size-3" />
                                                Rival
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <template v-if="getTag(opp) === 'nemesis'">Your win rate is under 40% against this opponent</template>
                                            <template v-else>Your win rate is between 40-60% against this opponent</template>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </TableCell>
                            <TableCell>
                                <span class="font-medium">{{ opp.username }}</span>
                            </TableCell>
                            <TableCell>
                                <TooltipProvider v-if="opp.archetypes.length > 0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <Tooltip v-for="archetype in opp.archetypes.slice(0, VISIBLE_ARCHETYPES)" :key="archetype.name">
                                            <TooltipTrigger>
                                                <div class="flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs">
                                                    <ManaSymbols :symbols="archetype.colorIdentity" />
                                                    <span class="max-w-24 truncate">{{ archetype.name }}</span>
                                                </div>
                                            </TooltipTrigger>
                                            <TooltipContent>{{ archetype.name }}</TooltipContent>
                                        </Tooltip>
                                        <Tooltip v-if="opp.archetypes.length > VISIBLE_ARCHETYPES">
                                            <TooltipTrigger>
                                                <span class="text-xs text-muted-foreground">+{{ opp.archetypes.length - VISIBLE_ARCHETYPES }} more</span>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <div class="flex flex-col gap-0.5">
                                                    <span v-for="arch in opp.archetypes.slice(VISIBLE_ARCHETYPES)" :key="arch.name">{{ arch.name }}</span>
                                                </div>
                                            </TooltipContent>
                                        </Tooltip>
                                    </div>
                                </TooltipProvider>
                                <span v-else class="text-muted-foreground text-sm">Unknown</span>
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col gap-1">
                                    <WinRateBar :winrate="winrate(opp)" size="sm" />
                                    <span class="text-xs tabular-nums text-muted-foreground">
                                        {{ opp.matchesWon }}W - {{ opp.matchesLost }}L
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell class="text-muted-foreground whitespace-nowrap text-sm">
                                {{ dayjs(opp.lastPlayedAt).fromNow() }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div v-if="totalPages > 1" class="justify-end py-2 text-right">
                    <Pagination
                        @update:page="(p: number) => currentPage = p"
                        v-slot="{ page }"
                        :items-per-page="PER_PAGE"
                        :total="filtered.length"
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
    </div>
</template>
