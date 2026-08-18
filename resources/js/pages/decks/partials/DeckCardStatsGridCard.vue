<script setup lang="ts">
import * as fmt from '@/components/cards/cardStatFormat';
import { Card } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { ShrinkKey } from '@/lib/stats/shrinkage';
import {
    CARD_STATS_COLUMNS,
    CASTING_METHOD_COLUMNS,
    DEFAULT_CARD_STATS_VISIBILITY,
    type CardStatsColumnKey,
    type CardStatsPerspective,
    type CardStatsVisibility,
} from '@/pages/decks/partials/cardStatsColumns';
import type { DeckCardStat } from '@/types/decks';
import { ImageOff } from 'lucide-vue-next';
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

/**
 * Surface at most this many visible stat columns (in canonical column order) to
 * fill the tile beside the card image without overflowing. Trimming columns via
 * the host's Columns menu changes which ones show.
 */
const MAX_FIELDS = 8;

type FieldRender =
    | { kind: 'winpct'; shrinkKey: ShrinkKey }
    | { kind: 'pct'; value: number | null; count: number }
    | { kind: 'count'; value: number; zeroDash: boolean };

type GridField = { key: CardStatsColumnKey; label: string; render: FieldRender };

function buildField(key: CardStatsColumnKey): Omit<GridField, 'key'> | null {
    const s = props.stat;
    switch (key) {
        case 'keptPct':
            return { label: 'Kept', render: { kind: 'pct', value: fmt.pct(s.keptGames, s.totalGames), count: s.keptGames } };
        case 'keptWinPct':
            return { label: 'Kept win', render: { kind: 'winpct', shrinkKey: 'kept' } };
        case 'castPct':
            return { label: 'Cast', render: { kind: 'pct', value: fmt.pct(s.castGames, s.totalGames), count: s.castGames } };
        case 'castWinPct':
            return { label: 'Cast win', render: { kind: 'winpct', shrinkKey: 'cast' } };
        case 'playedPct':
            return { label: 'Played', render: { kind: 'pct', value: fmt.pct(s.playedGames, s.totalGames), count: s.playedGames } };
        case 'kicked':
            return { label: 'Kicked', render: { kind: 'count', value: s.totalKicked, zeroDash: true } };
        case 'activated':
            return { label: 'Activated', render: { kind: 'count', value: s.totalActivated, zeroDash: true } };
        case 'pregamePct':
            return { label: 'Pregame', render: { kind: 'pct', value: fmt.pct(s.pregameGames, s.totalGames), count: s.pregameGames } };
        case 'pregameWinPct':
            return { label: 'Pregame win', render: { kind: 'winpct', shrinkKey: 'pregame' } };
        case 'seenPct':
            return { label: 'Seen', render: { kind: 'pct', value: fmt.pct(s.seenGames, s.totalGames), count: s.seenGames } };
        case 'seenWinPct':
            return { label: 'Seen win', render: { kind: 'winpct', shrinkKey: 'seen' } };
        case 'sbOutPct':
            return { label: 'SB out', render: { kind: 'pct', value: fmt.pct(s.sidedOutGames, s.postboardGames), count: s.sidedOutGames } };
        case 'sbInPct':
            return { label: 'SB in', render: { kind: 'pct', value: fmt.pct(s.sidedInGames, s.postboardGames), count: s.sidedInGames } };
        case 'games':
            return { label: 'Games', render: { kind: 'count', value: s.totalGames, zeroDash: false } };
        default: {
            const castingColumn = CASTING_METHOD_COLUMNS.find((col) => col.key === key);
            if (castingColumn) {
                return { label: castingColumn.label, render: { kind: 'count', value: s[castingColumn.statField] as number, zeroDash: true } };
            }

            // 'type' / 'sb' carry no stat value in tile form.
            return null;
        }
    }
}

const fields = computed<GridField[]>(() => {
    const out: GridField[] = [];
    for (const col of CARD_STATS_COLUMNS) {
        if (!props.visibleColumns[col.key]) continue;
        const field = buildField(col.key);
        if (!field) continue;
        out.push({ key: col.key, ...field });
        if (out.length >= MAX_FIELDS) break;
    }
    return out;
});

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
    <Card class="flex flex-row gap-3 p-3">
        <div class="relative aspect-[488/680] w-48 shrink-0 overflow-hidden rounded-md bg-muted">
            <img v-if="stat.image" :src="stat.image" :alt="stat.name" class="size-full object-cover" loading="lazy" />
            <div v-else class="flex size-full items-center justify-center text-muted-foreground/40">
                <ImageOff class="size-5" />
            </div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <div class="flex items-start gap-1.5">
                <span class="min-w-0 flex-1 truncate text-sm font-medium" :title="stat.name">{{ stat.name ?? 'Unknown' }}</span>
                <span
                    v-if="stat.isSideboard"
                    class="shrink-0 rounded border border-black/40 bg-muted px-1 py-0.5 text-[10px] leading-none text-muted-foreground"
                >
                    SB
                </span>
            </div>

            <dl class="flex flex-col gap-y-1 text-xs tabular-nums">
                <div v-for="field in fields" :key="field.key" class="flex items-baseline justify-between gap-2">
                    <dt class="truncate text-muted-foreground">{{ field.label }}</dt>
                    <dd class="shrink-0 text-right">
                        <template v-if="field.render.kind === 'winpct'">
                            <TooltipProvider v-if="samples[field.render.shrinkKey] > 0">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span class="cursor-default border-b border-dotted border-muted-foreground/40">
                                            <span class="font-medium" :class="winRateClass(shrunkWinPct(field.render.shrinkKey))">
                                                {{ shrunkWinPct(field.render.shrinkKey) }}%
                                            </span>
                                            <span class="text-[10px] text-muted-foreground">({{ samples[field.render.shrinkKey] }})</span>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" class="text-xs">
                                        {{ rawWinPctLabel(field.render.shrinkKey) }}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                            <span v-else class="text-muted-foreground">-</span>
                        </template>

                        <template v-else-if="field.render.kind === 'pct'">
                            <template v-if="field.render.value !== null">
                                {{ field.render.value }}%
                                <span class="text-[10px] text-muted-foreground">({{ field.render.count }})</span>
                            </template>
                            <span v-else class="text-muted-foreground">-</span>
                        </template>

                        <template v-else>
                            <template v-if="!field.render.zeroDash || field.render.value > 0">
                                {{ field.render.value }}
                            </template>
                            <span v-else class="text-muted-foreground">-</span>
                        </template>
                    </dd>
                </div>
            </dl>
        </div>
    </Card>
</template>
