<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { computed } from 'vue';

type StatRow = {
    group: 'all_games' | 'game_1' | 'game_2' | 'game_3';
    split: 'overall' | 'play' | 'draw';
    wins: number;
    losses: number;
    win_rate: number | null;
    mulligans: number | null;
    opponent_mulligans: number | null;
    turns: number | null;
};

const props = defineProps<{
    rows: StatRow[];
}>();

const groupLabels: Record<StatRow['group'], string> = {
    all_games: 'All Games',
    game_1: 'Game 1',
    game_2: 'Game 2',
    game_3: 'Game 3',
};

const splitLabels: Record<StatRow['split'], string> = {
    overall: 'Overall',
    play: 'On Play',
    draw: 'On Draw',
};

const groupedRows = computed(() => {
    const groups: StatRow['group'][] = ['all_games', 'game_1', 'game_2', 'game_3'];
    const splits: StatRow['split'][] = ['overall', 'play', 'draw'];
    return groups.map((group) => ({
        key: group,
        label: groupLabels[group],
        rows: splits
            .map((split) => props.rows.find((r) => r.group === group && r.split === split))
            .filter((r): r is StatRow => r !== undefined),
    }));
});

function fmtAvg(v: number | null): string {
    return v === null ? '—' : v.toFixed(2);
}

function fmtPct(v: number | null): string {
    return v === null ? '—' : `${v.toFixed(1)}%`;
}

function winRateClass(v: number | null): string {
    if (v === null) return 'text-muted-foreground';
    if (v > 50) return 'text-success';
    if (v < 50) return 'text-destructive';
    return '';
}
</script>

<template>
    <Card class="overflow-hidden">
        <Table>
            <TableHeader>
                <TableRow class="bg-muted/30">
                    <TableHead class="w-36"></TableHead>
                    <TableHead class="w-24"></TableHead>
                    <TableHead class="text-right">Wins</TableHead>
                    <TableHead class="text-right">Losses</TableHead>
                    <TableHead class="text-right">Win%</TableHead>
                    <TableHead class="border-l border-border/60 text-right">Mulls/G</TableHead>
                    <TableHead class="text-right">Opp Mulls/G</TableHead>
                    <TableHead class="border-l border-border/60 text-right">Turns/G</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <template v-for="(group, groupIdx) in groupedRows" :key="group.key">
                    <TableRow
                        v-for="(row, idx) in group.rows"
                        :key="`${group.key}-${row.split}`"
                        :class="[
                            idx === 0 && groupIdx > 0 ? 'border-t-2 border-border' : '',
                            idx === 0 ? 'bg-muted/10' : '',
                        ]"
                    >
                        <TableCell class="py-2 align-middle">
                            <span v-if="idx === 0" class="text-sm font-semibold tracking-tight">{{ group.label }}</span>
                        </TableCell>
                        <TableCell class="py-2 text-xs text-muted-foreground">
                            {{ splitLabels[row.split] }}
                        </TableCell>
                        <TableCell class="py-2 text-right tabular-nums">
                            <span :class="row.wins > 0 ? 'font-medium text-success' : 'text-muted-foreground'">{{ row.wins }}</span>
                        </TableCell>
                        <TableCell class="py-2 text-right tabular-nums">
                            <span :class="row.losses > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ row.losses }}</span>
                        </TableCell>
                        <TableCell class="py-2 text-right tabular-nums">
                            <span class="font-medium" :class="winRateClass(row.win_rate)">{{ fmtPct(row.win_rate) }}</span>
                        </TableCell>
                        <TableCell class="border-l border-border/60 py-2 text-right text-muted-foreground tabular-nums">
                            {{ fmtAvg(row.mulligans) }}
                        </TableCell>
                        <TableCell class="py-2 text-right text-muted-foreground tabular-nums">
                            {{ fmtAvg(row.opponent_mulligans) }}
                        </TableCell>
                        <TableCell class="border-l border-border/60 py-2 text-right text-muted-foreground tabular-nums">
                            {{ fmtAvg(row.turns) }}
                        </TableCell>
                    </TableRow>
                </template>
            </TableBody>
        </Table>
    </Card>
</template>
