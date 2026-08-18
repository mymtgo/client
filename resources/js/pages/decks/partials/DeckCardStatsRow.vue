<script setup lang="ts">
import * as fmt from '@/components/cards/cardStatFormat';
import { TableCell, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { ShrinkKey } from '@/lib/stats/shrinkage';
import {
    CASTING_METHOD_COLUMNS,
    DEFAULT_CARD_STATS_VISIBILITY,
    type CardStatsPerspective,
    type CardStatsVisibility,
} from '@/pages/decks/partials/cardStatsColumns';
import type { DeckCardStat } from '@/types/decks';
import { Check, Image } from 'lucide-vue-next';
import { computed } from 'vue';

type CardStat = DeckCardStat;

const props = withDefaults(
    defineProps<{
        stat: CardStat;
        shrunk: Readonly<Record<ShrinkKey, number>>;
        rawRates: Readonly<Record<ShrinkKey, number | null>>;
        samples: Readonly<Record<ShrinkKey, number>>;
        prior?: number;
        trust?: number;
        visibleColumns?: CardStatsVisibility;
        perspective?: CardStatsPerspective;
    }>(),
    {
        prior: 0.5,
        trust: 0,
        visibleColumns: () => ({ ...DEFAULT_CARD_STATS_VISIBILITY }),
        perspective: 'mine',
    },
);

const emit = defineEmits<{
    imageEnter: [stat: CardStat];
    imageMove: [event: MouseEvent];
    imageLeave: [];
}>();

const pct = fmt.pct;

const ALT_COST_LABELS: [keyof CardStat, string][] = [
    ['totalFlashback', 'flashback'],
    ['totalMadness', 'madness'],
    ['totalEvoked', 'evoke'],
    ['totalWarp', 'warp'],
    ['totalFreeCast', 'free'],
    ['totalBargained', 'bargain'],
    ['totalDashed', 'dash'],
    ['totalBestowed', 'bestow'],
    ['totalReplicated', 'replicate'],
    ['totalSpectacle', 'spectacle'],
    ['totalRebound', 'rebound'],
    ['totalEscaped', 'escape'],
    ['totalNinjutsu', 'ninjutsu'],
    ['totalSuspended', 'suspend'],
    ['totalBuyback', 'buyback'],
    ['totalDisturb', 'disturb'],
    ['totalForetold', 'foretell'],
    ['totalRetraced', 'retrace'],
    ['totalMayhem', 'mayhem'],
    ['totalMiracle', 'miracle'],
    ['totalGifted', 'gift'],
    ['totalCasualty', 'casualty'],
];

const altCostBreakdown = computed(() =>
    ALT_COST_LABELS.filter(([key]) => (props.stat[key] as number) > 0)
        .map(([key, label]) => `${props.stat[key]} ${label}`)
        .join(', '),
);

function shrunkWinPct(key: ShrinkKey): number {
    return fmt.shrunkWinPct(props.shrunk, key);
}

function rawWinPctLabel(key: ShrinkKey): string {
    return fmt.rawWinPctLabel(props.rawRates, props.samples, key, props.trust, props.prior, props.perspective);
}

function winRateClass(pctVal: number): string {
    return fmt.winRateClass(pctVal, props.perspective);
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
        <TableCell v-if="visibleColumns.type" class="text-muted-foreground">{{ stat.type ?? '-' }}</TableCell>
        <TableCell v-if="visibleColumns.sb" class="text-center">
            <Check v-if="stat.isSideboard" class="mx-auto size-3.5 text-muted-foreground" />
        </TableCell>
        <TableCell v-if="visibleColumns.keptPct" class="text-right tabular-nums">
            <template v-if="pct(stat.keptGames, stat.totalGames) !== null">
                {{ pct(stat.keptGames, stat.totalGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.keptGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.keptWinPct" class="text-right tabular-nums">
            <TooltipProvider v-if="samples.kept > 0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="cursor-default border-b border-dotted border-muted-foreground/40">
                            <span class="font-medium" :class="winRateClass(shrunkWinPct('kept'))"> {{ shrunkWinPct('kept') }}% </span>
                            <span class="text-[10px] text-muted-foreground">({{ samples.kept }})</span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top" class="text-xs">
                        {{ rawWinPctLabel('kept') }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.castPct" class="text-right tabular-nums">
            <template v-if="pct(stat.castGames, stat.totalGames) !== null">
                <TooltipProvider v-if="altCostBreakdown">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span class="cursor-default border-b border-dotted border-muted-foreground">
                                {{ pct(stat.castGames, stat.totalGames) }}%
                                <span class="text-[10px] text-muted-foreground">({{ stat.castGames }})</span>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top" class="text-xs">
                            {{ altCostBreakdown }}
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
        <TableCell v-if="visibleColumns.castWinPct" class="text-right tabular-nums">
            <TooltipProvider v-if="samples.cast > 0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="cursor-default border-b border-dotted border-muted-foreground/40">
                            <span class="font-medium" :class="winRateClass(shrunkWinPct('cast'))"> {{ shrunkWinPct('cast') }}% </span>
                            <span class="text-[10px] text-muted-foreground">({{ samples.cast }})</span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top" class="text-xs">
                        {{ rawWinPctLabel('cast') }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.playedPct" class="text-right tabular-nums">
            <template v-if="pct(stat.playedGames, stat.totalGames) !== null">
                {{ pct(stat.playedGames, stat.totalGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.playedGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.kicked" class="text-right tabular-nums">
            <template v-if="stat.totalKicked > 0">
                {{ stat.totalKicked }}
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.activated" class="text-right tabular-nums">
            <template v-if="stat.totalActivated > 0">
                {{ stat.totalActivated }}
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <template v-for="column in CASTING_METHOD_COLUMNS" :key="column.key">
            <TableCell v-if="visibleColumns[column.key]" class="text-right tabular-nums">
                <template v-if="(stat[column.statField] as number) > 0">
                    {{ stat[column.statField] }}
                </template>
                <span v-else class="text-muted-foreground">-</span>
            </TableCell>
        </template>
        <TableCell v-if="visibleColumns.pregamePct" class="text-right tabular-nums">
            <template v-if="stat.pregameGames > 0 && pct(stat.pregameGames, stat.totalGames) !== null">
                <TooltipProvider v-if="stat.pregameRevealedGames > 0 && stat.pregamePlayedGames > 0">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span class="cursor-default border-b border-dotted border-muted-foreground">
                                {{ pct(stat.pregameGames, stat.totalGames) }}%
                                <span class="text-[10px] text-muted-foreground">({{ stat.pregameGames }})</span>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top" class="text-xs">
                            <span>{{ stat.pregameRevealedGames }} revealed, {{ stat.pregamePlayedGames }} played</span>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <template v-else>
                    {{ pct(stat.pregameGames, stat.totalGames) }}%
                    <span class="text-[10px] text-muted-foreground">({{ stat.pregameGames }})</span>
                </template>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.pregameWinPct" class="text-right tabular-nums">
            <TooltipProvider v-if="samples.pregame > 0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="cursor-default border-b border-dotted border-muted-foreground/40">
                            <span class="font-medium" :class="winRateClass(shrunkWinPct('pregame'))"> {{ shrunkWinPct('pregame') }}% </span>
                            <span class="text-[10px] text-muted-foreground">({{ samples.pregame }})</span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top" class="text-xs">
                        {{ rawWinPctLabel('pregame') }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.seenPct" class="text-right tabular-nums">
            <template v-if="pct(stat.seenGames, stat.totalGames) !== null">
                {{ pct(stat.seenGames, stat.totalGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.seenGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.seenWinPct" class="text-right tabular-nums">
            <TooltipProvider v-if="samples.seen > 0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="cursor-default border-b border-dotted border-muted-foreground/40">
                            <span class="font-medium" :class="winRateClass(shrunkWinPct('seen'))"> {{ shrunkWinPct('seen') }}% </span>
                            <span class="text-[10px] text-muted-foreground">({{ samples.seen }})</span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top" class="text-xs">
                        {{ rawWinPctLabel('seen') }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.sbOutPct" class="text-right tabular-nums">
            <template v-if="pct(stat.sidedOutGames, stat.postboardGames) !== null">
                {{ pct(stat.sidedOutGames, stat.postboardGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.sidedOutGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.sbInPct" class="text-right tabular-nums">
            <template v-if="pct(stat.sidedInGames, stat.postboardGames) !== null">
                {{ pct(stat.sidedInGames, stat.postboardGames) }}%
                <span class="text-[10px] text-muted-foreground">({{ stat.sidedInGames }})</span>
            </template>
            <span v-else class="text-muted-foreground">-</span>
        </TableCell>
        <TableCell v-if="visibleColumns.games" class="text-right text-muted-foreground tabular-nums">
            {{ stat.totalGames }}
        </TableCell>
    </TableRow>
</template>
