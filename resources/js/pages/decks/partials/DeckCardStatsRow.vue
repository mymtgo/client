<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { TableCell, TableRow } from '@/components/ui/table';
import { Check, Image } from 'lucide-vue-next';

type CardStat = {
    name: string;
    oracleId: string;
    colorIdentity: string | null;
    type: string | null;
    image: string | null;
    isSideboard: boolean;
    totalGames: number;
    totalPossible: number;
    totalKept: number;
    keptGames: number;
    keptWon: number;
    keptLost: number;
    totalSeen: number;
    seenGames: number;
    seenWon: number;
    seenLost: number;
    totalCast: number;
    castGames: number;
    castWon: number;
    castLost: number;
    postboardGames: number;
    sidedOutGames: number;
    sidedInGames: number;
    totalPlayed: number;
    playedGames: number;
    totalKicked: number;
    totalActivated: number;
    totalFlashback: number;
    totalMadness: number;
    totalEvoked: number;
};

defineProps<{
    stat: CardStat;
}>();

const emit = defineEmits<{
    imageEnter: [stat: CardStat];
    imageMove: [event: MouseEvent];
    imageLeave: [];
}>();

function pct(num: number, denom: number): number | null {
    return denom > 0 ? Math.round((num / denom) * 100) : null;
}

function winRateClass(pctVal: number | null): string {
    if (pctVal === null) return 'text-muted-foreground';
    if (pctVal > 55) return 'text-success';
    if (pctVal < 45) return 'text-destructive';
    return '';
}
</script>

<template>
    <TableRow>
        <TableCell class="font-medium">
            <span class="flex items-center gap-1.5">
                <Image
                    v-if="stat.image"
                    class="size-3.5 shrink-0 cursor-pointer text-zinc-600 hover:text-zinc-400"
                    @mouseenter="emit('imageEnter', stat)"
                    @mousemove="(e: MouseEvent) => emit('imageMove', e)"
                    @mouseleave="emit('imageLeave')"
                />
                {{ stat.name ?? 'Unknown' }}
            </span>
        </TableCell>
        <TableCell class="text-muted-foreground">{{ stat.type ?? '-' }}</TableCell>
        <TableCell class="text-center">
            <Check v-if="stat.isSideboard" class="mx-auto size-3.5 text-muted-foreground" />
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.keptGames, stat.totalGames) !== null">
                {{ pct(stat.keptGames, stat.totalGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.keptGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.keptWon, stat.keptWon + stat.keptLost) !== null">
                <span class="font-medium" :class="winRateClass(pct(stat.keptWon, stat.keptWon + stat.keptLost))">
                    {{ pct(stat.keptWon, stat.keptWon + stat.keptLost) }}%
                </span>
                <span class="text-[10px] text-muted-foreground">({{ stat.keptWon + stat.keptLost }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.castGames, stat.totalGames) !== null">
                <TooltipProvider v-if="stat.totalFlashback > 0 || stat.totalMadness > 0 || stat.totalEvoked > 0">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span class="cursor-default border-b border-dotted border-muted-foreground">
                                {{ pct(stat.castGames, stat.totalGames) }}%
                                <span class="text-[10px] text-muted-foreground">({{ stat.castGames }})</span>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top" class="text-xs">
                            <span v-if="stat.totalFlashback > 0">{{ stat.totalFlashback }} flashback</span>
                            <span v-if="stat.totalMadness > 0">{{ stat.totalFlashback > 0 ? ', ' : '' }}{{ stat.totalMadness }} madness</span>
                            <span v-if="stat.totalEvoked > 0">{{ (stat.totalFlashback > 0 || stat.totalMadness > 0) ? ', ' : '' }}{{ stat.totalEvoked }} evoke</span>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <template v-else>
                    {{ pct(stat.castGames, stat.totalGames) }}%
                    <span class="text-[10px] text-muted-foreground">({{ stat.castGames }})</span>
                </template>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.castWon, stat.castWon + stat.castLost) !== null">
                <span class="font-medium" :class="winRateClass(pct(stat.castWon, stat.castWon + stat.castLost))">
                    {{ pct(stat.castWon, stat.castWon + stat.castLost) }}%
                </span>
                <span class="text-[10px] text-muted-foreground">({{ stat.castWon + stat.castLost }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.playedGames, stat.totalGames) !== null">
                {{ pct(stat.playedGames, stat.totalGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.playedGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="stat.totalKicked > 0">
                {{ stat.totalKicked }}
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="stat.totalActivated > 0">
                {{ stat.totalActivated }}
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.seenGames, stat.totalGames) !== null">
                {{ pct(stat.seenGames, stat.totalGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.seenGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.seenWon, stat.seenWon + stat.seenLost) !== null">
                <span class="font-medium" :class="winRateClass(pct(stat.seenWon, stat.seenWon + stat.seenLost))">
                    {{ pct(stat.seenWon, stat.seenWon + stat.seenLost) }}%
                </span>
                <span class="text-[10px] text-muted-foreground">({{ stat.seenWon + stat.seenLost }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.sidedOutGames, stat.postboardGames) !== null">
                {{ pct(stat.sidedOutGames, stat.postboardGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.sidedOutGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right tabular-nums">
            <template v-if="pct(stat.sidedInGames, stat.postboardGames) !== null">
                {{ pct(stat.sidedInGames, stat.postboardGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.sidedInGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell class="text-right text-muted-foreground tabular-nums">
            {{ stat.totalGames }}
        </TableCell>
    </TableRow>
</template>
