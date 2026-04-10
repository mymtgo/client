<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ManaSymbols from '@/components/ManaSymbols.vue';
import WinRateBar from '@/components/WinRateBar.vue';
import { Skull, Swords } from 'lucide-vue-next';

type Opponent = {
    playerId: number;
    username: string;
    matchesWon: number;
    matchesLost: number;
    formats: string[];
    archetypes: { name: string; colorIdentity: string | null }[];
    lastPlayedAt: string;
    lastPlayedAtHuman: string;
};

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    opponents: {
        data: Opponent[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { search: string; sort: string; format: string };
    allFormats: string[];
}>();

const VISIBLE_ARCHETYPES = 3;

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

const search = ref(props.filters.search);
const sortBy = ref(props.filters.sort);
const selectedFormat = ref(props.filters.format || null);

let debounceTimer: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        '/opponents',
        {
            search: search.value || undefined,
            sort: sortBy.value,
            format: selectedFormat.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(reload, 300);
});

watch([sortBy, selectedFormat], reload);
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-3">
            <Input v-model="search" placeholder="Search opponents..." class="w-56" />

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
                <Button size="sm" :variant="selectedFormat === null ? 'default' : 'outline'" @click="selectedFormat = null">All</Button>
                <Button
                    v-for="format in allFormats"
                    :key="format"
                    size="sm"
                    :variant="selectedFormat === format ? 'default' : 'outline'"
                    @click="selectedFormat = format"
                    >{{ format }}</Button
                >
            </div>
        </div>

        <!-- Opponents table -->
        <Card class="overflow-hidden">
            <Table>
                <TableHeader class="sticky top-0 z-10 backdrop-blur-sm">
                    <TableRow>
                        <TableHead class="w-0"></TableHead>
                        <TableHead>Opponent</TableHead>
                        <TableHead>Archetypes Seen</TableHead>
                        <TableHead>Record</TableHead>
                        <TableHead>Last Played</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="opponents.data.length === 0">
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
                    <TableRow v-for="opp in opponents.data" :key="opp.playerId">
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
                            <span v-else class="text-sm text-muted-foreground">Unknown</span>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-col gap-1">
                                <WinRateBar :winrate="winrate(opp)" size="sm" />
                                <span class="text-xs text-muted-foreground tabular-nums"> {{ opp.matchesWon }}W - {{ opp.matchesLost }}L </span>
                            </div>
                        </TableCell>
                        <TableCell class="text-sm whitespace-nowrap text-muted-foreground">
                            {{ opp.lastPlayedAtHuman }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <div v-if="opponents.last_page > 1" class="flex justify-end gap-1 px-2 py-2">
                <template v-for="link in opponents.links" :key="link.label">
                    <Button
                        v-if="link.url"
                        size="sm"
                        :variant="link.active ? 'default' : 'outline'"
                        @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                        v-html="link.label"
                    />
                </template>
            </div>
        </Card>
    </div>
</template>
