<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { ContextMenu, ContextMenuContent, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-vue-next';
import { computed, ref, useSlots } from 'vue';
import type { MatchupSpread } from '@/types/decks';

const slots = useSlots();

const props = defineProps<{
    matchupSpread: MatchupSpread[];
    timeframe: string;
}>();

const emit = defineEmits<{
    select: [row: MatchupSpread];
}>();

type SortKey = 'name' | 'matches' | 'record' | 'winrate' | 'game_winrate' | 'otp_winrate' | 'avg_turns';
const sortBy = ref<SortKey>('matches');
const sortDesc = ref(true);

const LOW_DATA_THRESHOLD = 4;

function toggleSort(key: SortKey) {
    if (sortBy.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortBy.value = key;
        sortDesc.value = key !== 'name';
    }
}

function sortValue(matchup: MatchupSpread, key: SortKey): number | string {
    switch (key) {
        case 'name':
            return matchup.name;
        case 'matches':
            return matchup.matches;
        case 'record':
            return matchup.match_wins;
        case 'winrate':
            return matchup.match_winrate;
        case 'game_winrate':
            return matchup.game_winrate;
        case 'otp_winrate':
            return matchup.otp_winrate;
        case 'avg_turns':
            return matchup.avg_turns ?? 0;
    }
}

function sortIcon(key: SortKey) {
    if (sortBy.value !== key) return ChevronsUpDown;
    return sortDesc.value ? ChevronDown : ChevronUp;
}

function sortMatchups(list: MatchupSpread[]) {
    return [...list].sort((a, b) => {
        const aVal = sortValue(a, sortBy.value);
        const bVal = sortValue(b, sortBy.value);
        const cmp = aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        return sortDesc.value ? -cmp : cmp;
    });
}

const mainMatchups = computed(() => sortMatchups(props.matchupSpread.filter(m => m.matches >= LOW_DATA_THRESHOLD)));
const lowDataMatchups = computed(() => sortMatchups(props.matchupSpread.filter(m => m.matches < LOW_DATA_THRESHOLD)));
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="px-0">
            <p v-if="!matchupSpread?.length" class="py-8 text-center text-sm text-muted-foreground">No matchup data yet.</p>
            <Table v-else>
                <TableHeader class="sticky top-0 z-10 backdrop-blur-sm">
                    <TableRow>
                        <TableHead class="w-8 pr-0 pl-3"></TableHead>
                        <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                            <span class="inline-flex items-center gap-1">Archetype <component :is="sortIcon('name')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('record')">
                            <span class="inline-flex items-center justify-end gap-1">Record <component :is="sortIcon('record')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('matches')">
                            <span class="inline-flex items-center justify-end gap-1">Matches <component :is="sortIcon('matches')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('winrate')">
                            <span class="inline-flex items-center justify-end gap-1">Win % <component :is="sortIcon('winrate')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer border-l border-border text-right select-none" @click="toggleSort('game_winrate')">
                            <span class="inline-flex items-center justify-end gap-1">Games <component :is="sortIcon('game_winrate')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('game_winrate')">
                            <span class="inline-flex items-center justify-end gap-1">Game % <component :is="sortIcon('game_winrate')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('otp_winrate')">
                            <span class="inline-flex items-center justify-end gap-1">OTP % <component :is="sortIcon('otp_winrate')" class="size-3" /></span>
                        </TableHead>
                        <TableHead class="cursor-pointer text-right select-none" @click="toggleSort('avg_turns')">
                            <span class="inline-flex items-center justify-end gap-1">Avg Turns <component :is="sortIcon('avg_turns')" class="size-3" /></span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <ContextMenu v-for="matchup in mainMatchups" :key="matchup.archetype_id">
                        <ContextMenuTrigger as-child>
                            <TableRow class="cursor-pointer" @click="emit('select', matchup)">
                                <TableCell class="w-8 pr-0 pl-3">
                                    <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                                </TableCell>
                                <TableCell class="truncate">{{ matchup.name }}</TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.match_record }}</TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.matches }}</TableCell>
                                <TableCell class="text-right">
                                    <span class="font-medium tabular-nums" :class="matchup.match_winrate > 50 ? 'text-success' : matchup.match_winrate < 50 ? 'text-destructive' : ''">{{ matchup.match_winrate }}%</span>
                                </TableCell>
                                <TableCell class="border-l border-border text-right text-muted-foreground tabular-nums">{{ matchup.game_record }}</TableCell>
                                <TableCell class="text-right">
                                    <span class="font-medium tabular-nums" :class="matchup.game_winrate > 50 ? 'text-success' : matchup.game_winrate < 50 ? 'text-destructive' : ''">{{ matchup.game_winrate }}%</span>
                                </TableCell>
                                <TableCell class="text-right">
                                    <span class="font-medium tabular-nums" :class="matchup.otp_winrate > 50 ? 'text-success' : matchup.otp_winrate < 50 ? 'text-destructive' : ''">{{ matchup.otp_winrate }}%</span>
                                </TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.avg_turns ?? '—' }}</TableCell>
                            </TableRow>
                        </ContextMenuTrigger>
                        <ContextMenuContent v-if="slots['row-actions']">
                            <slot name="row-actions" :row="matchup" />
                        </ContextMenuContent>
                    </ContextMenu>

                    <!-- Low data divider -->
                    <TableRow v-if="lowDataMatchups.length" class="pointer-events-none">
                        <TableCell colspan="9" class="py-1.5">
                            <div class="flex items-center gap-3">
                                <div class="h-px flex-1 bg-border" />
                                <span class="text-xs text-muted-foreground/60">Less than {{ LOW_DATA_THRESHOLD }} matches</span>
                                <div class="h-px flex-1 bg-border" />
                            </div>
                        </TableCell>
                    </TableRow>

                    <ContextMenu v-for="matchup in lowDataMatchups" :key="matchup.archetype_id">
                        <ContextMenuTrigger as-child>
                            <TableRow class="cursor-pointer opacity-60" @click="emit('select', matchup)">
                                <TableCell class="w-8 pr-0 pl-3">
                                    <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                                </TableCell>
                                <TableCell class="truncate">{{ matchup.name }}</TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.match_record }}</TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.matches }}</TableCell>
                                <TableCell class="text-right">
                                    <span class="font-medium tabular-nums" :class="matchup.match_winrate > 50 ? 'text-success' : matchup.match_winrate < 50 ? 'text-destructive' : ''">{{ matchup.match_winrate }}%</span>
                                </TableCell>
                                <TableCell class="border-l border-border text-right text-muted-foreground tabular-nums">{{ matchup.game_record }}</TableCell>
                                <TableCell class="text-right">
                                    <span class="font-medium tabular-nums" :class="matchup.game_winrate > 50 ? 'text-success' : matchup.game_winrate < 50 ? 'text-destructive' : ''">{{ matchup.game_winrate }}%</span>
                                </TableCell>
                                <TableCell class="text-right">
                                    <span class="font-medium tabular-nums" :class="matchup.otp_winrate > 50 ? 'text-success' : matchup.otp_winrate < 50 ? 'text-destructive' : ''">{{ matchup.otp_winrate }}%</span>
                                </TableCell>
                                <TableCell class="text-right text-muted-foreground tabular-nums">{{ matchup.avg_turns ?? '—' }}</TableCell>
                            </TableRow>
                        </ContextMenuTrigger>
                        <ContextMenuContent v-if="slots['row-actions']">
                            <slot name="row-actions" :row="matchup" />
                        </ContextMenuContent>
                    </ContextMenu>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
